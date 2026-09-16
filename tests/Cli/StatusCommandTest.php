<?php

declare(strict_types=1);

namespace Tests\Cli;

use BabelHub\Cli\ExitCode;
use PHPUnit\Framework\TestCase;
use Tests\Cli\Support\CliWorkspace;
use Tests\Cli\Support\FakeApiClient;
use Tests\Cli\Support\RecordedRequests;

/**
 * `babelhub status` — wydruk z §10 planu, który **sprzedaje wartość, zanim ktokolwiek otworzy panel**.
 *
 * Sprawdzamy trzy rzeczy, na których ten ekran stoi:
 *
 * 1. **Człowiek widzi dokładnie to, po co przyszedł**: pokrycie każdego języka z „ready /
 *    NOT READY”, blok kluczy z jednostką (`klucz × język` — bez niej `Total: 91` i
 *    `Translated: 182` czytają się jak błąd), linię QA i ostrzeżenie dla **każdego** języka,
 *    który nie jest gotowy. Ostrzeżenia idą na `stderr`, bo `stdout` jest wynikiem.
 * 2. **`--output=json` przepisuje odpowiedź serwisu dosłownie.** Pusta lista ma zostać `[]`,
 *    a pusta mapa `{}` — po drodze przez `json_decode` + `json_encode` mapa stałaby się listą,
 *    czyli innym typem, niż obiecuje API, i skrypt klienta przewróciłby się dopiero u niego.
 * 3. **`status` to odczyt, nie bramka.** Projekt bez gotowego języka nadal kończy się zerem:
 *    bramką jest `validate`, a mieszanie tych dwóch rzeczy kończy się tym, że nikt nie uruchamia
 *    statusu w CI, bo „psuje build”.
 */
final class StatusCommandTest extends TestCase
{
    private const STATUS_PATH = '/api/v1/projects/learnhub/status';

    private CliWorkspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = new CliWorkspace();
        $this->workspace->writeProjectConfig();
        $this->workspace->writeCredentials();
    }

    protected function tearDown(): void
    {
        $this->workspace->cleanup();

        parent::tearDown();
    }

    public function testTheHumanPrintoutCarriesCoverageTheKeysBlockTheQaLineAndTheWarnings(): void
    {
        $client = $this->client()->script('GET', self::STATUS_PATH, $this->statusBody());

        $result = $this->workspace->run(['command' => 'status'], $client);

        self::assertSame(ExitCode::Success->value, $result['code'], 'status niczego nie zmienia i nie jest bramką — brak gotowości to nie błąd');

        $stdout = $result['stdout'];
        self::assertStringContainsString('QA demo', $stdout, 'wydruk otwiera nazwa projektu');
        self::assertStringContainsString('en_GB  100%   ready', $stdout, 'kod języka, pokrycie i gotowość w jednej linii');
        self::assertStringContainsString('pl_PL  100%   ready', $stdout);
        self::assertStringContainsString('de_DE   76%   NOT READY', $stdout, 'język niegotowy musi być widoczny na pierwszy rzut oka, nie tylko w JSON-ie');

        self::assertStringContainsString('Total:        91', $stdout, 'Total liczy klucze…');
        self::assertStringContainsString(
            'Translated:   251  (klucz × język)',
            $stdout,
            '…a Translated tłumaczenia (klucz × język) — jednostka jest w nawiasie, bo bez niej te liczby wyglądają na sprzeczne',
        );
        self::assertStringContainsString('Missing:      22  (klucz × język)', $stdout);
        self::assertStringContainsString('Review:       3', $stdout, 'pozycje do przejrzenia też są częścią obrazu projektu');

        self::assertStringContainsString('QA errors:        1', $stdout);
        self::assertStringContainsString('QA warnings:      2', $stdout);
        self::assertStringContainsString('Last sync:        2026-09-14T09:13:27+00:00', $stdout);

        self::assertStringContainsString('de_DE is not ready.', $result['stderr'], 'ostrzeżenie mówi wprost, który język blokuje wydanie');
        self::assertStringNotContainsString('is not ready', $stdout, 'ostrzeżenie na stdout psułoby każdy skrypt parsujący wynik');
    }

    public function testEveryLocaleThatIsNotReadyGetsItsOwnWarning(): void
    {
        $client = $this->client()->script('GET', self::STATUS_PATH, $this->statusBody(['de_DE', 'pl_PL']));

        $result = $this->workspace->run(['command' => 'status'], $client);

        self::assertSame(ExitCode::Success->value, $result['code'], 'niegotowy język to fakt do pokazania, a nie powód do kodu 6');
        self::assertStringContainsString('de_DE is not ready.', $result['stderr']);
        self::assertStringContainsString(
            'pl_PL is not ready.',
            $result['stderr'],
            'każdy niegotowy język ma własne ostrzeżenie — inaczej człowiek naprawia jeden i wraca do CI po drugi',
        );
        self::assertStringContainsString(
            'pl_PL  100%   NOT READY',
            $result['stdout'],
            '100% pokrycia i brak gotowości współistnieją: gotowość wymaga też braku błędów QA',
        );
    }

    public function testInJsonModeTheServerBodyIsPrintedVerbatim(): void
    {
        $body = '{"application":"learnhub","name":"QA demo","source_locale":"en_GB",'
            . '"keys":{"total":2,"removed":0},'
            . '"locales":{"fr_FR":{"total":2,"approved":0,"missing":2,"coverage":0,"complete":false,"ready":false,"version":1,"counts":{}}},'
            . '"ready":false,"not_ready":["fr_FR"],'
            . '"qa":{"pass":true,"locale":null,"summary":{"errors":0,"warnings":0,"info":0,"total":0},"findings":[]},'
            . '"last_sync_at":null}';

        $client = $this->client()->scriptRaw('GET', self::STATUS_PATH, 200, $body);

        $result = $this->workspace->run(['command' => 'status', '--output' => 'json'], $client);

        self::assertSame(ExitCode::Success->value, $result['code']);
        self::assertSame(
            $body . "\n",
            $result['stdout'],
            '`jsonBody` przepisuje ciało dosłownie: pusta lista zostaje `[]`, a pusta mapa `{}` — przez json_decode+json_encode mapa stałaby się listą',
        );
        self::assertSame(1, substr_count($result['stdout'], "\n"), 'na stdout jest dokładnie jeden obiekt JSON');
        self::assertStringNotContainsString('Locales', $result['stdout'], 'wydruk dla człowieka nie może wchodzić w tryb json');
        self::assertStringNotContainsString('Total:', $result['stdout']);
        self::assertStringContainsString('fr_FR is not ready.', $result['stderr'], 'ostrzeżenia zostają na stderr także w trybie json');
    }

    public function testTheLocaleOptionNarrowsTheQualityCheckInTheRequestPath(): void
    {
        $path = self::STATUS_PATH . '?locale=de_DE';
        $client = $this->client()->script('GET', $path, $this->statusBody());

        $result = $this->workspace->run(['command' => 'status', '--locale' => 'de_DE'], $client);

        self::assertSame(ExitCode::Success->value, $result['code']);
        self::assertSame(
            [$path],
            (new RecordedRequests($client))->paths(),
            'język jedzie w adresie: pokrycie liczone jest zawsze dla wszystkich, zawęża się tylko kontrola jakości',
        );
    }

    public function testInPlainModeTheNumbersArePrintedWithoutTheOrnaments(): void
    {
        $client = $this->client()->script('GET', self::STATUS_PATH, $this->statusBody());

        $result = $this->workspace->run(['command' => 'status', '--output' => 'plain'], $client);

        self::assertSame(ExitCode::Success->value, $result['code']);

        $stdout = $result['stdout'];
        self::assertStringContainsString(
            'de_DE   76%   NOT READY',
            $stdout,
            'wiersze języków są wynikiem, więc tryb plain je pokazuje — po to, żeby `status --output=plain | grep NOT` działało w CI',
        );
        self::assertStringContainsString('Total:        91', $stdout);
        self::assertStringContainsString('QA errors:        1', $stdout);
        self::assertStringNotContainsString('Locales', $stdout, 'nagłówki i linie są ozdobnikami dla człowieka — do trybu plain nie trafiają');
        self::assertStringNotContainsString('Keys', $stdout);
        self::assertStringNotContainsString('QA demo', $stdout);
        self::assertStringNotContainsString('is not ready', $stdout, 'ostrzeżenia zostają na stderr w każdym trybie');
        self::assertStringContainsString('de_DE is not ready.', $result['stderr']);
    }

    public function testAForbiddenAnswerGivesTheUnauthorizedCode(): void
    {
        $client = $this->client()->script('GET', self::STATUS_PATH, [
            'error' => 'forbidden',
            'message' => 'Brak uprawnień translations:read.',
        ], 403);

        $result = $this->workspace->run(['command' => 'status'], $client);

        self::assertSame(ExitCode::Unauthorized->value, $result['code'], 'brak zakresu to „napraw poświadczenie”, a nie „nie wiem, co się stało”');
        self::assertSame('', $result['stdout'], 'nieudany status nie ma wyniku');
        self::assertStringContainsString('Brak uprawnień translations:read.', $result['stderr']);
    }

    /**
     * Odpowiedź statusu z §10 planu: trzy języki (dwa gotowe, jeden nie), klucze, QA i data
     * ostatniej synchronizacji. Liczby są spójne, żeby asercje na wydruku miały jedno źródło.
     *
     * @param list<string> $notReady
     *
     * @return array<string, mixed>
     */
    private function statusBody(array $notReady = ['de_DE']): array
    {
        $rows = [
            'en_GB' => ['approved' => 91, 'coverage' => 100, 'version' => 2],
            'pl_PL' => ['approved' => 91, 'coverage' => 100, 'version' => 13],
            'de_DE' => ['approved' => 69, 'coverage' => 76, 'version' => 5],
        ];

        $locales = [];
        foreach ($rows as $code => $row) {
            $ready = !in_array($code, $notReady, true);
            $missing = 91 - $row['approved'];

            $locales[$code] = [
                'total' => 91,
                'approved' => $row['approved'],
                'missing' => $missing,
                'coverage' => $row['coverage'],
                'complete' => $ready,
                'ready' => $ready,
                'version' => $row['version'],
                'counts' => $ready
                    ? ['APPROVED' => $row['approved']]
                    : ['APPROVED' => $row['approved'], 'MISSING' => $missing, 'REVIEW' => 3],
            ];
        }

        return [
            'application' => 'learnhub',
            'name' => 'QA demo',
            'source_locale' => 'en_GB',
            'keys' => ['total' => 91, 'removed' => 0],
            'locales' => $locales,
            'ready' => $notReady === [],
            'not_ready' => $notReady,
            'qa' => [
                'pass' => false,
                'locale' => null,
                'summary' => ['errors' => 1, 'warnings' => 2, 'info' => 0, 'total' => 3],
                'findings' => [],
            ],
            'last_sync_at' => '2026-09-14T09:13:27+00:00',
        ];
    }

    private function client(): FakeApiClient
    {
        return new FakeApiClient(CliWorkspace::API_URL, CliWorkspace::TOKEN);
    }
}
