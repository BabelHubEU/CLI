<?php

declare(strict_types=1);

namespace Tests\Cli;

use BabelHub\Cli\Checks\CheckName;
use BabelHub\Cli\ExitCode;
use PHPUnit\Framework\TestCase;
use Tests\Cli\Support\CliWorkspace;
use Tests\Cli\Support\FakeApiClient;
use Tests\Cli\Support\RecordedRequests;

/**
 * Cztery **nazwane kontrole PR** (Fala 1, pozycja 1.14) — i różnice, których nie wolno zatrzeć.
 *
 * Kryterium z planu jest jedno: *„nazwana kontrola jest zrozumiała bez czytania logu CI —
 * developer widzi, co dokładnie blokuje merge”*. Dlatego ten plik sprawdza nie tyle „działa”,
 * ile **czym się te kontrole różnią**:
 *
 * * `coverage` to widok raportu QA na ustalenia o kodzie `missing` — bez własnej definicji braku,
 *   więc nie może raportować niczego innego (ani placeholdera, ani duplikatu);
 * * `acceptance` czyta **status**, nie QA, bo QA liczy ustalenia wobec wartości zatwierdzonych
 *   i nie odróżnia „nikt nie przetłumaczył” od „tłumaczenie czeka na zatwierdzenie”;
 * * `acceptance` jest **ostrzeżeniem** (praca w toku, nie defekt), więc domyślna bramka ją
 *   przepuszcza, a `--fail-on=warning` czyni z niej twardą bramkę — jawnie, w CI zespołu;
 * * zestaw kontroli deklaruje repozytorium (`checks.run`), a flaga `--check` wygrywa z plikiem.
 *
 * Trzy rzeczy pilnowane osobno, bo każda z nich jest cichym fałszywym sukcesem, jeśli zniknie:
 * literówka w `--check` (kod 2 i **zero żądań**), język spoza projektu w `--check=acceptance`
 * (kod 5, a nie „zero niezatwierdzonych”), oraz jedno żądanie na endpoint, a nie na kontrolę
 * (`quality` i `coverage` czytają ten sam raport).
 */
final class ValidateNamedChecksTest extends TestCase
{
    private const QA_PATH = '/api/v1/projects/learnhub/qa';
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

    public function testEveryNamedCheckIsAcceptedByTheFlag(): void
    {
        self::assertSame(
            ['quality', 'coverage', 'acceptance', 'hardcoded-strings'],
            CheckName::names(),
            'lista nazw jest kontraktem: trafia do --help, do checks.run i do nazw kroków w CI',
        );
    }

    public function testCoverageReportsOnlyMissingTranslations(): void
    {
        $client = $this->client()->script('POST', self::QA_PATH, $this->qaBody([
            $this->finding('missing', 'error', 'billing.plan.monthly', 'pl_PL'),
            $this->finding('placeholder_missing', 'error', 'home.title', 'pl_PL'),
            $this->finding('duplicate_value', 'warning', 'about.text', 'pl_PL'),
        ]));

        $result = $this->workspace->run(['command' => 'validate', '--check' => 'coverage'], $client);

        self::assertSame(ExitCode::CheckFailed->value, $result['code'], 'brak tłumaczenia blokuje bramkę');
        self::assertStringContainsString('ERROR missing pl_PL billing.plan.monthly', $result['stdout']);
        self::assertStringNotContainsString('placeholder_missing', $result['stdout'], 'coverage to braki, nie cała jakość');
        self::assertStringNotContainsString('duplicate_value', $result['stdout']);
        self::assertStringContainsString(
            'Kontrola coverage — brakujących tłumaczeń: 1 (pl_PL: 1).',
            $result['stdout'],
            'podsumowanie podaje liczbę braków i rozkład po językach',
        );
        self::assertStringContainsString('coverage (1 brakujących tłumaczeń)', $result['stderr']);
        self::assertSame([self::QA_PATH], (new RecordedRequests($client))->paths(), 'coverage czyta ten sam raport QA');
    }

    public function testCoveragePassesWhenNothingIsMissing(): void
    {
        $client = $this->client()->script('POST', self::QA_PATH, $this->qaBody([
            // Ostrzeżenia i błędy jakości **nie są** brakami: o tym jest `quality`.
            $this->finding('duplicate_value', 'warning', 'about.text', 'pl_PL'),
            $this->finding('icu_syntax', 'error', 'home.title', 'pl_PL'),
        ]));

        $result = $this->workspace->run(['command' => 'validate', '--check' => 'coverage'], $client);

        self::assertSame(ExitCode::Success->value, $result['code'], 'kompletny katalog przechodzi kontrolę braków');
        self::assertStringContainsString('Kontrola coverage — brakujących tłumaczeń: 0.', $result['stdout']);
        self::assertStringContainsString('Lokalizacja przeszła kontrolę.', $result['stderr']);
    }

    public function testAcceptanceReadsTheStatusAndCountsEveryUnapprovedStatus(): void
    {
        $client = $this->client()->script('GET', self::STATUS_PATH, $this->statusBody([
            'en_GB' => ['APPROVED' => 91],
            'pl_PL' => ['APPROVED' => 79, 'DRAFT' => 5, 'TRANSLATED' => 2, 'REVIEW' => 5, 'MISSING' => 0],
        ]));

        $result = $this->workspace->run(['command' => 'validate', '--check' => 'acceptance'], $client);

        self::assertSame(
            ExitCode::Success->value,
            $result['code'],
            'niezatwierdzone tłumaczenie to zwykle praca w toku: domyślna bramka blokuje tylko błędy',
        );
        self::assertStringContainsString('WARNING unapproved pl_PL — W pl_PL 12 tłumaczeń nie jest zatwierdzonych', $result['stdout']);
        self::assertStringContainsString('szkic: 5, przetłumaczone: 2, do przeglądu: 5', $result['stdout'], 'zdanie mówi, na jakim etapie są te wartości');
        self::assertStringContainsString('Kontrola acceptance — niezatwierdzonych tłumaczeń: 12 (pl_PL: 12).', $result['stdout']);
        self::assertSame([self::STATUS_PATH], (new RecordedRequests($client))->paths(), 'acceptance czyta status, nie raport QA');
    }

    public function testTheAcceptanceCheckBecomesAHardGateAtTheWarningThreshold(): void
    {
        $client = $this->client()->script('GET', self::STATUS_PATH, $this->statusBody([
            'pl_PL' => ['APPROVED' => 90, 'DRAFT' => 1],
        ]));

        $result = $this->workspace->run(
            ['command' => 'validate', '--check' => 'acceptance', '--fail-on' => 'warning'],
            $client,
        );

        self::assertSame(ExitCode::CheckFailed->value, $result['code'], 'zespół, który chce twardej bramki, mówi to wprost');
        self::assertStringContainsString('acceptance (1 niezatwierdzonych tłumaczeń)', $result['stderr']);
        self::assertStringContainsString('próg --fail-on=warning', $result['stderr'], 'komunikat mówi, dlaczego ostrzeżenie zatrzymało build');
    }

    public function testAcceptanceIsSilentForALocaleWithoutUnapprovedValues(): void
    {
        $client = $this->client()->script('GET', self::STATUS_PATH, $this->statusBody([
            'en_GB' => ['APPROVED' => 91],
            'pl_PL' => ['APPROVED' => 91],
        ]));

        $result = $this->workspace->run(['command' => 'validate', '--check' => 'acceptance'], $client);

        self::assertSame(ExitCode::Success->value, $result['code']);
        self::assertStringContainsString('Kontrola acceptance — niezatwierdzonych tłumaczeń: 0.', $result['stdout']);
        self::assertStringNotContainsString('WARNING', $result['stdout'], 'brak ustaleń to brak linii ustaleń');
    }

    /**
     * **Literówka w języku nie może dać zielonej bramki.** `GET …/status` przyjmuje `?locale=`
     * (zawęża ustalenia QA), ale nieznany język nie jest tam błędem — a `acceptance` czyta
     * właśnie status. Bez własnego guardu `--locale=pl` dawałoby „0 niezatwierdzonych”, czyli
     * dokładnie ten cichy fałszywy sukces, przed którym broni cała ta komenda.
     */
    public function testALocaleOutsideTheProjectIsRejectedInsteadOfPassingSilently(): void
    {
        $client = $this->client()->script('GET', self::STATUS_PATH, $this->statusBody([
            'en_GB' => ['APPROVED' => 91],
            'pl_PL' => ['APPROVED' => 90, 'DRAFT' => 1],
        ]));

        $result = $this->workspace->run(
            ['command' => 'validate', '--check' => 'acceptance', '--locale' => 'pl'],
            $client,
        );

        self::assertSame(ExitCode::Rejected->value, $result['code'], 'kod 5: „popraw wywołanie”, a nie „tłumaczenia są złe”');
        self::assertStringContainsString('nie prowadzi języka „pl”', $result['stderr']);
        self::assertStringContainsString('en_GB, pl_PL', $result['stderr'], 'komunikat mówi, co wolno wpisać');
        self::assertSame('', $result['stdout'], 'nieudana kontrola nie ma wyniku do sparsowania');
    }

    public function testTheLocaleNarrowsTheAcceptanceCheckToOneLanguage(): void
    {
        $client = $this->client()->script('GET', self::STATUS_PATH, $this->statusBody([
            'en_GB' => ['APPROVED' => 90, 'DRAFT' => 1],
            'pl_PL' => ['APPROVED' => 91],
        ]));

        $result = $this->workspace->run(
            ['command' => 'validate', '--check' => 'acceptance', '--locale' => 'pl_PL'],
            $client,
        );

        self::assertSame(ExitCode::Success->value, $result['code']);
        self::assertStringContainsString('niezatwierdzonych tłumaczeń: 0', $result['stdout'], 'zawężenie obowiązuje także tutaj');
        self::assertSame(
            [],
            (new RecordedRequests($client))->payload('GET', self::STATUS_PATH),
            'języka nie wysyłamy w adresie: w `status` `?locale=` zawęża co innego (ustalenia QA)',
        );
    }

    public function testTheConfiguredSetRunsSeveralChecksInOneInvocation(): void
    {
        $this->workspace->writeProjectConfigYaml(checksRun: ['quality', 'coverage', 'acceptance']);

        $client = $this->client()
            ->script('POST', self::QA_PATH, $this->qaBody([
                $this->finding('missing', 'error', 'billing.plan.monthly', 'pl_PL'),
            ]))
            ->script('GET', self::STATUS_PATH, $this->statusBody([
                'pl_PL' => ['APPROVED' => 90, 'DRAFT' => 1],
            ]));

        $result = $this->workspace->run(['command' => 'validate'], $client);

        self::assertSame(ExitCode::CheckFailed->value, $result['code']);
        self::assertStringContainsString('Kontrola jakości: 1 błędów', $result['stdout']);
        self::assertStringContainsString('Kontrola coverage — brakujących tłumaczeń: 1', $result['stdout']);
        self::assertStringContainsString('Kontrola acceptance — niezatwierdzonych tłumaczeń: 1', $result['stdout']);
        self::assertStringContainsString(
            'Lokalizacja nie przeszła kontroli: quality (1 błędów), coverage (1 brakujących tłumaczeń).',
            $result['stderr'],
            'werdykt nazywa **każdą** kontrolę, która zatrzymała bramkę',
        );

        $requests = new RecordedRequests($client);
        self::assertSame(
            1,
            $requests->countPath(self::QA_PATH),
            'quality i coverage czytają ten sam raport: jedno żądanie, nie jedno na kontrolę',
        );
        self::assertSame(2, $requests->count(), 'dwa endpointy, dwa żądania');
    }

    public function testTheMultiCheckDocumentKeepsOneShapeAndNamesTheFailures(): void
    {
        $this->workspace->writeProjectConfigYaml(checksRun: ['coverage', 'acceptance']);

        $client = $this->client()
            ->script('POST', self::QA_PATH, $this->qaBody([
                $this->finding('missing', 'error', 'billing.plan.monthly', 'pl_PL'),
            ]))
            ->script('GET', self::STATUS_PATH, $this->statusBody([
                'pl_PL' => ['APPROVED' => 91],
            ]));

        $result = $this->workspace->run(['command' => 'validate', '--output' => 'json'], $client);

        /** @var array<string, mixed> $document */
        $document = json_decode(trim($result['stdout']), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(1, substr_count($result['stdout'], "\n"), 'w trybie json na stdout jest dokładnie jeden dokument');
        self::assertSame(['coverage', 'acceptance'], array_keys($document['checks']), 'kolejność jest ta z `checks.run`');
        self::assertFalse($document['pass']);
        self::assertSame(['coverage'], $document['failures'], 'werdykt maszynowy nazywa kontrole, które nie przeszły');
        self::assertSame(['missing' => 1, 'locales' => ['pl_PL' => 1]], $document['checks']['coverage']['summary']);
        self::assertTrue($document['checks']['acceptance']['pass'], 'acceptance przy domyślnym progu przechodzi');
    }

    public function testTheFlagWinsOverTheConfiguredSet(): void
    {
        $this->workspace->writeProjectConfigYaml(checksRun: ['quality', 'coverage', 'acceptance']);

        $client = $this->client()->script('GET', self::STATUS_PATH, $this->statusBody([
            'pl_PL' => ['APPROVED' => 91],
        ]));

        $result = $this->workspace->run(['command' => 'validate', '--check' => 'acceptance'], $client);

        self::assertSame(ExitCode::Success->value, $result['code']);
        self::assertSame(
            [self::STATUS_PATH],
            (new RecordedRequests($client))->paths(),
            '`--check` to decyzja na jedno uruchomienie: nie wolno przy okazji wołać reszty zestawu',
        );
        self::assertStringContainsString('Kontrola acceptance', $result['stdout']);
        self::assertStringNotContainsString('Kontrola jakości', $result['stdout']);
    }

    public function testALocalCheckCanStandNextToAServerCheck(): void
    {
        $this->workspace->writeProjectConfigYaml(checksRun: ['hardcoded-strings', 'coverage']);
        $this->workspace->write('src/Panel.php', "<?php\n\n\$title = 'Welcome back to the dashboard';\n");

        $client = $this->client()->script('POST', self::QA_PATH, $this->qaBody([
            $this->finding('missing', 'error', 'billing.plan.monthly', 'pl_PL'),
        ]));

        $result = $this->workspace->run(['command' => 'validate', '--fail-on' => 'warning'], $client);

        self::assertSame(ExitCode::CheckFailed->value, $result['code']);
        self::assertStringContainsString('WARNING hardcoded_string src/Panel.php:3', $result['stdout']);
        self::assertStringContainsString('ERROR missing pl_PL billing.plan.monthly', $result['stdout']);
        self::assertStringContainsString('hardcoded-strings (1 ostrzeżeń)', $result['stderr']);
        self::assertStringContainsString('coverage (1 brakujących tłumaczeń)', $result['stderr']);
        self::assertSame(
            1,
            (new RecordedRequests($client))->count(),
            'kontrola lokalna nie idzie do serwisu — jedno żądanie pochodzi tylko z `coverage`',
        );
    }

    public function testAnUnknownSeverityFromTheServerDoesNotBlockAndKeepsItsRawName(): void
    {
        $client = $this->client()->script('POST', self::QA_PATH, [
            'application' => 'learnhub',
            'pass' => false,
            'locale' => null,
            'summary' => ['errors' => 0, 'warnings' => 0, 'info' => 0, 'total' => 1],
            'findings' => [$this->finding('brand_new_rule', 'critical', 'home.title', 'pl_PL')],
        ]);

        $result = $this->workspace->run(['command' => 'validate', '--check' => 'coverage', '--output' => 'json'], $client);

        /** @var array<string, mixed> $document */
        $document = json_decode(trim($result['stdout']), true, 512, JSON_THROW_ON_ERROR);
        // `coverage` filtruje po kodzie, więc nowa reguła nie jest brakiem — a gdyby trafiła do
        // `quality`, nieznana waga **nie może** zablokować cudzego merge'a (stary klient, nowy serwis).
        $quality = $this->workspace->run(['command' => 'validate', '--check' => 'quality', '--output' => 'json'], $client);

        /** @var array<string, mixed> $qualityDocument */
        $qualityDocument = json_decode(trim($quality['stdout']), true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($document['checks']['coverage']['pass'], 'coverage patrzy na kod `missing`, nie na wagi');
        self::assertSame(ExitCode::Success->value, $quality['code'], 'nieznana waga nie blokuje: fałszywy alarm uczy obchodzenia bramki');
        self::assertSame('critical', $qualityDocument['checks']['quality']['findings'][0]['severity'], 'surowa waga zostaje w JSON-ie');
        self::assertSame(0, $qualityDocument['checks']['quality']['blocking']);
    }

    public function testAnApiFailureInsideACheckIsReportedWithTheCheckName(): void
    {
        $client = $this->client()->script('POST', self::QA_PATH, [
            'error' => 'forbidden',
            'message' => 'Poświadczenie nie ma zakresu qa:read.',
        ], 403);

        $result = $this->workspace->run(['command' => 'validate', '--check' => 'coverage'], $client);

        self::assertSame(ExitCode::Unauthorized->value, $result['code'], '403 to „napraw poświadczenie”, nie „popraw tłumaczenia”');
        self::assertStringContainsString('Kontrola coverage: Poświadczenie nie ma zakresu qa:read.', $result['stderr']);
        self::assertSame('', $result['stdout'], 'nieudana kontrola nie ma wyniku do sparsowania');
    }

    /**
     * @return array{code: string, severity: string, key: string, locale: string, message: string, params: array<string, mixed>}
     */
    private function finding(string $code, string $severity, string $key, string $locale): array
    {
        return [
            'code' => $code,
            'severity' => $severity,
            'key' => $key,
            'locale' => $locale,
            'message' => sprintf('Ustalenie %s dla klucza „%s”.', $code, $key),
            'params' => ['key' => $key],
        ];
    }

    /**
     * Odpowiedź `POST /qa` z licznikami wyliczonymi z listy ustaleń.
     *
     * @param list<array{code: string, severity: string, key: string, locale: string, message: string, params: array<string, mixed>}> $findings
     *
     * @return array<string, mixed>
     */
    private function qaBody(array $findings): array
    {
        $summary = ['errors' => 0, 'warnings' => 0, 'info' => 0, 'total' => count($findings)];
        foreach ($findings as $finding) {
            $summary[match ($finding['severity']) {
                'warning' => 'warnings',
                'info' => 'info',
                default => 'errors',
            }]++;
        }

        return [
            'application' => 'learnhub',
            'pass' => $summary['errors'] === 0,
            'locale' => null,
            'summary' => $summary,
            'findings' => $findings,
        ];
    }

    /**
     * Odpowiedź `GET /status` w kształcie, który zna CLI: `locales.<kod>.counts.<STATUS>`.
     *
     * @param array<string, array<string, int>> $counts
     *
     * @return array<string, mixed>
     */
    private function statusBody(array $counts): array
    {
        $locales = [];
        foreach ($counts as $code => $statuses) {
            $locales[$code] = [
                'total' => 91,
                'approved' => $statuses['APPROVED'] ?? 0,
                'missing' => 91 - ($statuses['APPROVED'] ?? 0),
                'coverage' => 100,
                'complete' => true,
                'ready' => true,
                'version' => 13,
                'counts' => $statuses + ['MISSING' => 0, 'DRAFT' => 0, 'TRANSLATED' => 0, 'REVIEW' => 0, 'APPROVED' => 0],
            ];
        }

        return [
            'application' => 'learnhub',
            'name' => 'LearnHub',
            'source_locale' => 'en_GB',
            'keys' => ['total' => 91, 'removed' => 0],
            'locales' => $locales,
            'ready' => true,
            'not_ready' => [],
            'qa' => ['pass' => true, 'locale' => null, 'summary' => ['errors' => 0, 'warnings' => 0, 'info' => 0, 'total' => 0], 'findings' => []],
            'last_sync_at' => null,
        ];
    }

    private function client(): FakeApiClient
    {
        return new FakeApiClient(CliWorkspace::API_URL, CliWorkspace::TOKEN);
    }
}
