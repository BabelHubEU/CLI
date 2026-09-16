<?php

declare(strict_types=1);

namespace Tests\Cli;

use BabelHub\Cli\ExitCode;
use PHPUnit\Framework\TestCase;
use Tests\Cli\Support\CliWorkspace;
use Tests\Cli\Support\FakeApiClient;
use Tests\Cli\Support\RecordedRequests;

/**
 * `babelhub validate` — **bramka CI**: `0`, gdy czysto, `6`, gdy kontrola coś znalazła.
 *
 * To funkcja, za którą płaci się najchętniej, i miejsce, w którym najłatwiej stracić zaufanie —
 * dlatego test pilnuje trzech rzeczy naraz:
 *
 * 1. **Kod wyjścia jest jednoznaczny i różny dla różnych przyczyn.** `6` znaczy „sprawdzone
 *    i coś znalazłem”, `5` — „nie udało się sprawdzić” (`422`: język spoza projektu), `2` —
 *    „popraw wywołanie”. Pipeline musi to rozróżniać, bo „nie umiem sprawdzić” nie może
 *    wyglądać jak „jest dobrze” (dokładnie ten błąd popełnia `crowdin download`, wychodząc zerem
 *    mimo awarii).
 * 2. **Próg ważności jest jawny.** Domyślnie blokuje tylko `error`; `--fail-on=warning` to
 *    świadoma decyzja zespołu, a nie ukryte ustawienie — fałszywy alarm blokujący merge uczy
 *    ludzi obchodzenia bramki, a wtedy kontrola przestaje istnieć. Nieznana wartość progu to
 *    błąd użycia **przed** jakimkolwiek żądaniem.
 * 3. **`plain` jest do `grep`-a.** Jedna linia na ustalenie (`WAGA kod klucz — zdanie`),
 *    a `json` przepisuje ciało odpowiedzi dosłownie (pusta mapa `params` zostaje `{}`).
 *
 * Próg domyślny można też ustawić **w repozytorium** (`checks.fail_on` w `.babelhub.yml`) —
 * to sprawdza `ValidateThresholdFromConfigTest` niżej: plik deklaruje, co blokuje merge,
 * a flaga `--fail-on` wygrywa z plikiem na jedno uruchomienie.
 */
final class ValidateCommandTest extends TestCase
{
    private const QA_PATH = '/api/v1/projects/learnhub/qa';

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

    public function testAPassingReportIsZero(): void
    {
        $client = $this->client()->script('POST', self::QA_PATH, $this->qaBody());

        $result = $this->workspace->run(['command' => 'validate'], $client);

        self::assertSame(ExitCode::Success->value, $result['code'], '„sprawdzone i czyste” to jedyne zero — każdy inny kod znaczy „nie udało się sprawdzić”');
        self::assertStringContainsString('Kontrola jakości: 0 błędów, 0 ostrzeżeń, 0 informacji.', $result['stdout']);
        self::assertStringContainsString('Lokalizacja przeszła kontrolę.', $result['stderr']);
        self::assertStringNotContainsString('ERROR', $result['stdout'], 'brak ustaleń to brak linii ustaleń');
    }

    public function testErrorsFailTheGateAndTheReportGoesToTheProjectsQaEndpoint(): void
    {
        $client = $this->client()->script('POST', self::QA_PATH, $this->qaBody([
            [
                'code' => 'placeholder_missing',
                'severity' => 'error',
                'key' => 'billing.plan.monthly',
                'message' => 'W kluczu „billing.plan.monthly” brakuje placeholderów ze źródła: amount.',
            ],
        ]));

        $result = $this->workspace->run(['command' => 'validate'], $client);

        self::assertSame(
            ExitCode::CheckFailed->value,
            $result['code'],
            'kod 6, nie 1: problem jest w tłumaczeniach, a nie w narzędziu — pipeline czyta to jako „nie merguj”, a nie „awaria”',
        );
        self::assertStringContainsString('Kontrola jakości: 1 błędów, 0 ostrzeżeń, 0 informacji.', $result['stdout']);
        self::assertStringContainsString('ERROR placeholder_missing pl_PL billing.plan.monthly — W kluczu', $result['stdout']);
        self::assertStringContainsString(
            'Lokalizacja nie przeszła kontroli: quality (1 błędów).',
            $result['stderr'],
            'zdanie na stderr nazywa kontrolę, która zatrzymała bramkę, i mówi, ile błędów to zrobiło',
        );
        self::assertSame(
            [self::QA_PATH],
            (new RecordedRequests($client))->paths(),
            'kontrola jakości to POST — obliczenie, a nie odczyt zasobu, którego może dotknąć prefetch',
        );
    }

    public function testThePlainModePrintsOneGrepableLinePerFinding(): void
    {
        $client = $this->client()->script('POST', self::QA_PATH, $this->qaBody([
            [
                'code' => 'placeholder_missing',
                'severity' => 'error',
                'key' => 'billing.plan.monthly',
                'message' => 'W kluczu „billing.plan.monthly” brakuje placeholderów ze źródła: amount.',
            ],
            [
                'code' => 'duplicate_value',
                'severity' => 'warning',
                'key' => 'home.title',
                'message' => 'Ten sam tekst występuje przy innym źródle.',
            ],
            [
                'code' => 'empty',
                'severity' => 'info',
                'key' => 'about.text',
                'message' => 'Tekst jest pusty.',
            ],
        ]));

        $result = $this->workspace->run(['command' => 'validate', '--output' => 'plain'], $client);

        self::assertSame(ExitCode::CheckFailed->value, $result['code'], 'kod wyjścia nie zależy od trybu wyjścia');

        $lines = array_values(array_filter(
            explode("\n", $result['stdout']),
            static fn (string $line): bool => $line !== '',
        ));
        $findingLines = array_values(array_filter(
            $lines,
            static fn (string $line): bool => preg_match('/^(ERROR|WARNING|INFO) /', $line) === 1,
        ));

        self::assertSame([
            'ERROR placeholder_missing pl_PL billing.plan.monthly — W kluczu „billing.plan.monthly” brakuje placeholderów ze źródła: amount.',
            'WARNING duplicate_value pl_PL home.title — Ten sam tekst występuje przy innym źródle.',
            'INFO empty pl_PL about.text — Tekst jest pusty.',
        ], $findingLines, 'jedna linia na ustalenie, z wagą, kodem, językiem i kluczem — log CI da się filtrować bez parsowania JSON-a');

        self::assertStringNotContainsString('<', $result['stdout'], 'w trybie plain nie ma ozdób ani tagów Symfony — linia ma być gotowa do `grep`-a');
        self::assertStringContainsString(
            'Kontrola jakości: 1 błędów, 1 ostrzeżeń, 1 informacji.',
            $result['stdout'],
            'podsumowanie jest wynikiem, a nie komunikatem, więc w trybie plain zostaje na stdout (w json zostaje samo ciało odpowiedzi)',
        );
    }

    public function testWarningsOnlyPassByDefaultAndFailAtTheWarningThreshold(): void
    {
        $client = $this->client()->script('POST', self::QA_PATH, $this->qaBody([
            [
                'code' => 'duplicate_value',
                'severity' => 'warning',
                'key' => 'home.title',
                'message' => 'Ten sam tekst występuje przy innym źródle.',
            ],
        ]));

        $loose = $this->workspace->run(['command' => 'validate'], $client);

        self::assertSame(
            ExitCode::Success->value,
            $loose['code'],
            'domyślnie blokuje tylko BŁĄD: fałszywy alarm blokujący merge uczy ludzi obchodzenia bramki, a wtedy kontrola przestaje istnieć',
        );
        self::assertStringContainsString('Kontrola jakości: 0 błędów, 1 ostrzeżeń, 0 informacji.', $loose['stdout']);
        self::assertStringContainsString('Lokalizacja przeszła kontrolę.', $loose['stderr']);

        $strict = $this->workspace->run(['command' => 'validate', '--fail-on' => 'warning'], $client);

        self::assertSame(ExitCode::CheckFailed->value, $strict['code'], 'ostrzejszy próg jest jawną decyzją zespołu, którą wpisuje się w CI');
        self::assertStringContainsString(
            'próg --fail-on=warning',
            $strict['stderr'],
            'komunikat mówi, dlaczego build padł — inaczej człowiek szuka błędu w tłumaczeniach',
        );
        self::assertStringContainsString(
            'quality (0 błędów, 1 ostrzeżeń)',
            $strict['stderr'],
            'przy progu `warning` blokują obie wagi, więc zdanie pokazuje obie liczby',
        );
    }

    public function testTheLocaleIsSentInTheBodyOnlyWhenAsked(): void
    {
        $client = $this->client()->script('POST', self::QA_PATH, $this->qaBody());

        $withoutLocale = $this->workspace->run(['command' => 'validate'], $client);
        self::assertSame(ExitCode::Success->value, $withoutLocale['code']);

        $withLocale = $this->workspace->run(['command' => 'validate', '--locale' => 'pl_PL'], $client);
        self::assertSame(ExitCode::Success->value, $withLocale['code']);

        $requests = new RecordedRequests($client);
        self::assertSame([], $requests->payload('POST', self::QA_PATH, 0), 'bez --locale ciało jest puste — serwis sprawdza wtedy wszystkie języki projektu');
        self::assertSame(['locale' => 'pl_PL'], $requests->payload('POST', self::QA_PATH, 1), 'język jedzie w ciele POST-a, a nie w adresie');
    }

    public function testAnUnknownThresholdIsAUsageErrorAndNoRequestIsSent(): void
    {
        $client = $this->client();

        $result = $this->workspace->run(['command' => 'validate', '--fail-on' => 'notice'], $client);

        self::assertSame(ExitCode::Usage->value, $result['code'], 'literówka w progu to błąd wywołania, a nie „sprawdzone i czyste”');
        self::assertStringContainsString('Nieznana wartość --fail-on', $result['stderr']);
        self::assertStringContainsString('error, warning', $result['stderr'], 'komunikat mówi, co wolno wpisać');
        self::assertSame(
            0,
            (new RecordedRequests($client))->count(),
            'błąd użycia nie ma po co iść do serwisu — inaczej literówka w CI kosztuje żądanie i wpis w limiterze',
        );
    }

    public function testALocaleOutsideTheProjectIsRejectedWithCodeFive(): void
    {
        $client = $this->client()->script('POST', self::QA_PATH, [
            'error' => 'language_not_found',
            'message' => 'Projekt nie prowadzi języka „de_DE”.',
        ], 422);

        $result = $this->workspace->run(['command' => 'validate', '--locale' => 'de_DE'], $client);

        self::assertSame(
            ExitCode::Rejected->value,
            $result['code'],
            '422 znaczy „popraw dane wywołania”; kod 6 mówiłby „tłumaczenia są złe”, choć nie sprawdzono ani jednego',
        );
        self::assertStringContainsString('Projekt nie prowadzi języka', $result['stderr']);
        self::assertSame('', $result['stdout'], 'nieudana kontrola nie ma wyniku do sparsowania');
    }

    /**
     * Wynik maszynowy ma **jeden kształt**, niezależnie od liczby kontroli — a ciało odpowiedzi
     * serwisu jest w nim obecne **dosłownie**.
     *
     * Dwie rzeczy są tu kontraktem i obie zostały złamane w pierwszej wersji tego kodu:
     *
     * * kształt nie może zależeć od tego, ile kontroli uruchomiono (inaczej dopisanie `coverage`
     *   do `checks.run` psuje skrypt parsujący wynik u klienta — bez żadnej zmiany w CLI);
     * * pusta mapa (`params: {}`) musi zostać pustą mapą, a nie stać się pustą listą: kontrakt API
     *   opisuje `params` jako obiekt, więc `json_decode(…, true)` + `json_encode` zmieniłby typ.
     *   Właśnie dlatego raport jest trzymany jako `stdClass`, a nie tablica.
     */
    public function testInJsonModeTheDocumentHasAStableShapeAndTheReportVerbatim(): void
    {
        $body = '{"pass":false,"locale":"pl_PL","summary":{"errors":1,"warnings":0,"info":0,"total":1},'
            . '"findings":[{"code":"empty","severity":"error","key":"about.text","locale":"pl_PL",'
            . '"message":"Tekst jest pusty.","params":{}}],"application":"learnhub"}';

        $client = $this->client()->scriptRaw('POST', self::QA_PATH, 200, $body);

        $result = $this->workspace->run(['command' => 'validate', '--output' => 'json', '--locale' => 'pl_PL'], $client);

        self::assertSame(ExitCode::CheckFailed->value, $result['code'], 'kod wyjścia nie zależy od trybu wyjścia');
        self::assertSame(1, substr_count($result['stdout'], "\n"), 'na stdout jest dokładnie jeden obiekt');
        self::assertStringNotContainsString('Kontrola jakości', $result['stdout'], 'w trybie json poza dokumentem nie ma na stdout niczego');
        self::assertStringContainsString('Lokalizacja nie przeszła kontroli', $result['stderr'], 'człowiek nadal dostaje zdanie o wyniku — na stderr');

        /** @var array<string, mixed> $document */
        $document = json_decode(trim($result['stdout']), true, 512, JSON_THROW_ON_ERROR);
        $check = $document['checks']['quality'];

        self::assertFalse($document['pass']);
        self::assertSame(['quality'], $document['failures'], 'werdykt zbiorczy nazywa kontrole, które zatrzymały bramkę');
        self::assertSame('error', $document['fail_on'], 'próg jest w dokumencie, bo bez niego `pass` przy ostrzeżeniach wygląda sprzecznie');
        self::assertSame(1, $check['blocking']);
        self::assertSame('pl_PL', $check['findings'][0]['locale']);
        self::assertStringContainsString(
            '"report":' . $body,
            $result['stdout'],
            'raport jest dosłownym ciałem odpowiedzi — porównanie idzie po **tekście** wyjścia, bo ' .
            'przepuszczenie go przez `json_decode` w teście zamieniłoby `{}` na `[]` i test ' .
            'mierzyłby własną stratę, a nie zachowanie CLI',
        );
        self::assertSame(
            2,
            substr_count($result['stdout'], '"params":{}'),
            'pusta mapa zostaje mapą w obu miejscach: w ustaleniach i w raporcie',
        );
    }

    /**
     * Odpowiedź `POST /qa` bez ustaleń — z licznikami wyliczonymi z listy, żeby test nie mógł
     * przypadkiem sprawdzać liczby, która nie odpowiada ustaleniom.
     *
     * `locale` w ustaleniach jest **zawsze** ustawione (serwis nie wysyła ustalenia bez języka),
     * a nie `null`: linia w trybie `plain` bez języka dawała dwie identyczne pozycje dla tego
     * samego klucza w dwóch językach — czyli log, w którym nie da się odróżnić, co poprawić.
     *
     * @param list<array{code: string, severity: string, key: string, message: string}> $findings
     *
     * @return array<string, mixed>
     */
    private function qaBody(array $findings = []): array
    {
        /** @var array{errors: int, warnings: int, info: int, total: int} $summary */
        $summary = ['errors' => 0, 'warnings' => 0, 'info' => 0, 'total' => count($findings)];

        foreach ($findings as $finding) {
            $counter = match ($finding['severity']) {
                'warning' => 'warnings',
                'info' => 'info',
                default => 'errors',
            };
            $summary[$counter]++;
        }

        return [
            'application' => 'learnhub',
            'pass' => $summary['errors'] === 0,
            'locale' => null,
            'summary' => $summary,
            'findings' => array_map(
                static fn (array $finding): array => $finding + ['locale' => 'pl_PL', 'params' => []],
                $findings,
            ),
        ];
    }

    private function client(): FakeApiClient
    {
        return new FakeApiClient(CliWorkspace::API_URL, CliWorkspace::TOKEN);
    }
}
