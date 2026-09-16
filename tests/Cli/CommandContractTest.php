<?php

declare(strict_types=1);

namespace Tests\Cli;

use BabelHub\Cli\Console\Application;
use BabelHub\Cli\ExitCode;
use BabelHub\Cli\Http\ApiClient;
use BabelHub\Cli\Http\ApiResponse;
use BabelHub\Cli\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Tests\Cli\Support\CliWorkspace;
use Tests\Cli\Support\FakeApiClient;

/**
 * Kontrakt, którego przestrzega **każde** polecenie — nie „tabela w dokumentacji” (1.16 i 1.17).
 *
 * Testy pojedynczych komend sprawdzają, że każda z osobna robi swoje. Ten plik sprawdza cztery
 * rzeczy **w poprzek wszystkich komend**, bo właśnie na przecięciach powstają dziury, których
 * pojedyncze testy nie widzą:
 *
 * 1. **kod wyjścia z odpowiedzi serwisu** — `401`/`403` → `3`, `429` → `4`, `404`/`422` → `5`,
 *    `5xx` → `1`. Macierz „komenda × przyczyna”, bo rozproszone mapowanie kończy się tym, że
 *    jedna komenda zwraca `1` tam, gdzie reszta zwraca `4` — i skrypt rozróżniający limit od
 *    awarii przestaje działać tylko dla niej;
 * 2. **zdanie pochodzi z ciała odpowiedzi, nie z kodu HTTP** — `500` z komunikatem serwisu ma
 *    pokazać ten komunikat, bo „HTTP 500” nie mówi nikomu nic, czego nie wie już z kodu wyjścia;
 * 3. **`stdout` w trybie `json` jest pusty przy niepowodzeniu** — połowa dokumentu JSON jest
 *    gorsza niż brak dokumentu, bo parser dostaje obiekt bez pól i „działa”;
 * 4. **błąd użycia to `2`, a nie `1`.** Symfony domyślnie sam łapie wyjątki i zwraca `1`, więc
 *    `--output=xml` wychodził kodem „awaria narzędzia” i ze stosem wywołań na ekranie.
 *
 * Do tego trzy obietnice z planu, które łatwo złożyć i nigdy nie sprawdzić: `--timeout` **faktycznie**
 * dociera do klienta HTTP (wcześniej nie docierał — flaga nie robiła nic), `--version` mówi prawdę,
 * a brak poświadczenia mówi, co zrobić.
 */
final class CommandContractTest extends TestCase
{
    private CliWorkspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = new CliWorkspace();
    }

    protected function tearDown(): void
    {
        $this->workspace->cleanup();

        parent::tearDown();
    }

    /**
     * Pierwsze żądanie każdej komendy rozmawiającej z serwisem.
     *
     * `scan` i `logout` nie mają tu czego szukać (nie rozmawiają z API), a `login` ma własny
     * suite — parowanie to przepływ, nie jedno żądanie.
     *
     * @return iterable<string, array{0: array<string, mixed>, 1: string, 2: string, 3: bool, 4: bool}>
     *   argumenty, metoda, ścieżka pierwszego żądania, czy potrzebuje kluczy w kodzie, czy dotyczy projektu
     */
    public static function httpCommands(): iterable
    {
        yield 'whoami' => [['command' => 'whoami'], 'GET', '/api/v1/me', false, false];
        yield 'init' => [
            ['command' => 'init', '--project' => 'learnhub', '--source-locale' => 'en_GB'],
            'GET',
            '/api/v1/projects',
            false,
            true,
        ];
        yield 'pull' => [['command' => 'pull'], 'GET', '/api/v1/projects/learnhub', false, true];
        yield 'push' => [['command' => 'push'], 'POST', '/api/v1/projects/learnhub/sync', true, true];
        yield 'sync' => [['command' => 'sync'], 'POST', '/api/v1/projects/learnhub/sync', true, true];
        yield 'status' => [['command' => 'status'], 'GET', '/api/v1/projects/learnhub/status', false, true];
        yield 'validate' => [['command' => 'validate'], 'POST', '/api/v1/projects/learnhub/qa', false, true];
    }

    /**
     * Odpowiedzi, które serwis może dać, i kod wyjścia, który z nich wynika.
     *
     * @return iterable<string, array{0: int, 1: string, 2: string, 3: ExitCode}>
     */
    public static function failures(): iterable
    {
        yield 'brak poświadczenia (401)' => [401, 'unauthorized', 'Token wygasł albo został odwołany.', ExitCode::Unauthorized];
        yield 'brak zakresu (403)' => [403, 'forbidden', 'Brak uprawnień translations:read.', ExitCode::Unauthorized];
        yield 'limit żądań (429)' => [429, 'rate_limited', 'Za dużo żądań — spróbuj za chwilę.', ExitCode::RateLimited];
        yield 'nie ma projektu (404)' => [404, 'application_not_found', 'Projekt nie istnieje.', ExitCode::Rejected];
        yield 'niepoprawne dane (422)' => [422, 'validation', 'Pole "keys" jest wymagane.', ExitCode::Rejected];
        yield 'awaria serwisu (500)' => [500, 'server_error', 'Nieoczekiwany błąd serwisu.', ExitCode::Failure];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('httpCommands')]
    public function testEveryCommandUsesTheDocumentedExitCodeAndPrintsTheApiMessage(
        array $arguments,
        string $method,
        string $path,
        bool $needsKeys,
        bool $projectScoped,
    ): void {
        foreach (self::failures() as $label => [$status, $code, $message, $expected]) {
            $workspace = new CliWorkspace();
            $workspace->writeCredentials();
            if ($needsKeys) {
                $workspace->writeProjectConfig();
                $workspace->write('src/Keys.php', "<?php\n\ntrans('a.one');\n");
            } elseif ($projectScoped && ($arguments['command'] ?? '') !== 'init') {
                // `init` sam tworzy konfigurację — dlatego nie wolno mu jej podłożyć.
                $workspace->writeProjectConfig();
            }

            $client = (new FakeApiClient())->script($method, $path, ['error' => $code, 'message' => $message], $status);
            $result = $workspace->run($arguments, $client, null, $projectScoped);

            self::assertSame(
                $expected->value,
                $result['code'],
                sprintf('%s / %s: kod wyjścia musi wynikać z odpowiedzi serwisu', $arguments['command'], $label),
            );
            self::assertStringContainsString(
                $message,
                $result['stderr'],
                sprintf('%s / %s: człowiek ma przeczytać zdanie serwisu', $arguments['command'], $label),
            );
            self::assertSame(
                '',
                $result['stdout'],
                sprintf('%s / %s: niepowodzenie nie wypisuje wyniku na stdout', $arguments['command'], $label),
            );

            $workspace->cleanup();
        }
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('httpCommands')]
    public function testAFailureInJsonModeLeavesStdoutEmptySoNothingIsHalfParsed(
        array $arguments,
        string $method,
        string $path,
        bool $needsKeys,
        bool $projectScoped,
    ): void {
        $this->workspace->writeCredentials();
        if ($needsKeys) {
            $this->workspace->writeProjectConfig();
            $this->workspace->write('src/Keys.php', "<?php\n\ntrans('a.one');\n");
        } elseif ($projectScoped && ($arguments['command'] ?? '') !== 'init') {
            $this->workspace->writeProjectConfig();
        }

        $client = (new FakeApiClient())->script($method, $path, ['error' => 'forbidden', 'message' => 'Brak zakresu.'], 403);
        $result = $this->workspace->run($arguments + ['--output' => 'json'], $client, null, $projectScoped);

        self::assertSame(ExitCode::Unauthorized->value, $result['code']);
        self::assertSame('', $result['stdout'], 'tryb json nie może wypuścić połowy dokumentu');
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('httpCommands')]
    public function testATransportFailureIsLocalFailureAndSaysWhereItFailed(
        array $arguments,
        string $method,
        string $path,
        bool $needsKeys,
        bool $projectScoped,
    ): void {
        $this->workspace->writeCredentials();
        if ($needsKeys) {
            $this->workspace->writeProjectConfig();
            $this->workspace->write('src/Keys.php', "<?php\n\ntrans('a.one');\n");
        } elseif ($projectScoped && ($arguments['command'] ?? '') !== 'init') {
            $this->workspace->writeProjectConfig();
        }

        $client = (new FakeApiClient())->failTransport('Connection refused');
        $result = $this->workspace->run($arguments, $client, null, $projectScoped);

        self::assertSame(ExitCode::Failure->value, $result['code'], '„nie połączyłem się” to awaria, nie odrzucenie');
        self::assertStringContainsString('Connection refused', $result['stderr']);
        self::assertStringContainsString(
            CliWorkspace::API_URL,
            $result['stderr'],
            'komunikat ma wskazać adres, z którym się nie udało połączyć',
        );
        self::assertSame('', $result['stdout']);
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function usageMistakes(): iterable
    {
        yield 'nieznany tryb wyjścia' => [['--output' => 'xml'], 'Nieznany tryb wyjścia'];
        yield 'limit czasu z jednostką' => [['--timeout' => '60s'], 'Nieznany limit czasu'];
        yield 'limit czasu równy zero' => [['--timeout' => '0'], 'Nieznany limit czasu'];
    }

    /**
     * @param array<string, mixed> $flags
     */
    #[DataProvider('usageMistakes')]
    public function testABadFlagIsAUsageErrorAndNotACrashWithAStackTrace(array $flags, string $hint): void
    {
        $this->workspace->writeProjectConfig();
        $this->workspace->writeCredentials();

        $result = $this->workspace->run(['command' => 'status'] + $flags, new FakeApiClient());

        self::assertSame(ExitCode::Usage->value, $result['code'], 'złe wywołanie to 2, a nie awaria narzędzia');
        self::assertStringContainsString($hint, $result['stderr'], 'komunikat ma nazwać flagę, która jest zła');
        self::assertStringNotContainsString('#0 ', $result['stderr'], 'stos wywołań w CI nikt nie czyta');
        self::assertStringNotContainsString('Stack trace', $result['stderr']);
        self::assertSame('', $result['stdout']);
    }

    public function testTheTimeoutFlagActuallyReachesTheHttpClient(): void
    {
        $this->workspace->writeProjectConfig();
        $this->workspace->writeCredentials();

        $captured = null;
        $factory = static function (string $url, ?string $token, float $timeout) use (&$captured): FakeApiClient {
            $captured = $timeout;

            return (new FakeApiClient())->script('GET', '/api/v1/projects/learnhub/status', ['ready' => true]);
        };

        $result = $this->workspace->run(['command' => 'status', '--timeout' => '3'], new FakeApiClient(), $factory);

        self::assertSame(ExitCode::Success->value, $result['code']);
        self::assertSame(3.0, $captured, '`--timeout` musi dojechać do klienta HTTP, a nie zostać w warstwie flag');
    }

    public function testTheDefaultTimeoutIsShortAndPositive(): void
    {
        self::assertGreaterThan(0.0, ApiClient::DEFAULT_TIMEOUT_SECONDS);
        self::assertLessThanOrEqual(
            30.0,
            ApiClient::DEFAULT_TIMEOUT_SECONDS,
            'domyślny limit ma być krótki: 700 sekund czekania (domyślne u konkurencji) czyni CLI bezużytecznym w CI',
        );
    }

    /**
     * Zepsuta konfiguracja projektu to błąd **użycia** (`2`), a nie awaria narzędzia (`1`).
     *
     * Ten test powstał po live checku: `status` w katalogu z niepoprawnym YAML-em wychodził
     * kodem `1`, bo wyjątek z wczytywania konfiguracji leciał jako nieprzewidziany. Tabela
     * w `docs/cli.md` mówi wprost, że `2` znaczy „popraw wywołanie **albo konfigurację**” —
     * a skrypt CI, który dostaje `1`, idzie szukać awarii infrastruktury zamiast literówki
     * w pliku, który właśnie zmienił.
     */
    public function testABrokenConfigurationFileIsAUsageError(): void
    {
        $brokenYaml = "project: learnhub\n  source_locale: en_GB\n";
        $valid = "project: learnhub\nsource_locale: en_GB\nfiles:\n  pattern: \"translations/messages.{lang}.php\"\n";

        /** @var array<string, array{0: array<string, string>, 1: string}> $cases */
        $cases = [
            'zepsuty YAML' => [['.babelhub.yml' => $brokenYaml], 'YAML'],
            'dwa pliki konfiguracji' => [['.babelhub.yml' => $valid, '.babelhub.json' => '{}'], 'oba pliki'],
            'nieznany klucz' => [['.babelhub.yml' => $valid . "checks:\n  hardcoded: true\n"], 'hardcoded'],
            'JSON, który nie jest JSON-em' => [['.babelhub.json' => '{nie json'], 'JSON'],
        ];

        foreach ($cases as $label => [$files, $expected]) {
            $workspace = new CliWorkspace();
            $workspace->writeCredentials();
            foreach ($files as $name => $contents) {
                $workspace->write($name, $contents);
            }

            $result = $workspace->run(['command' => 'status'], new FakeApiClient());

            self::assertSame(ExitCode::Usage->value, $result['code'], $label . ': kod 2, nie 1');
            self::assertStringContainsString($expected, $result['stderr'], $label . ': komunikat mówi, co poprawić');
            self::assertSame('', $result['stdout'], $label . ': brak wyniku, bo nie ma z czego go policzyć');

            $workspace->cleanup();
        }
    }

    public function testWhenTheTimeoutFlagIsMissingTheClientGetsTheDefault(): void
    {
        $this->workspace->writeProjectConfig();
        $this->workspace->writeCredentials();

        $captured = null;
        $factory = static function (string $url, ?string $token, float $timeout) use (&$captured): FakeApiClient {
            $captured = $timeout;

            return (new FakeApiClient())->script('GET', '/api/v1/projects/learnhub/status', ['ready' => true]);
        };

        $this->workspace->run(['command' => 'status'], new FakeApiClient(), $factory);

        self::assertSame(ApiClient::DEFAULT_TIMEOUT_SECONDS, $captured);
    }

    public function testTheVersionFlagPrintsTheVersionTheCodeActuallyIs(): void
    {
        $result = $this->workspace->run(['--version' => true], new FakeApiClient());

        self::assertSame(ExitCode::Success->value, $result['code']);
        self::assertStringContainsString(Version::VALUE, $result['stdout']);
        self::assertStringNotContainsString(
            'UNKNOWN',
            $result['stdout'],
            'Symfony wypisuje `UNKNOWN`, gdy aplikacja nie ma wersji — to jest dokładnie to kłamstwo, którego szukamy',
        );
        self::assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+(-[0-9A-Za-z.\-]+)?$/',
            Version::VALUE,
            'wersja musi być wersją, a nie słowem: od niej zależą warunki w skryptach',
        );
        self::assertStringContainsString(
            Version::VALUE,
            Version::clientName(),
            'serwis widzi wersję klienta w nagłówku User-Agent — musi być ta sama',
        );
    }

    public function testEveryCommandExplainsWhatToDoWhenThereIsNoCredential(): void
    {
        // Bez poświadczenia: brak pliku, brak zmiennej, brak flagi.
        $previous = getenv('BABELHUB_TOKEN');
        putenv('BABELHUB_TOKEN');

        /** @var list<array{0: string, 1: bool}> $commands komenda, czy dotyczy projektu */
        $commands = [
            ['whoami', false],
            ['status', true],
            ['validate', true],
            ['pull', true],
            ['push', true],
            ['sync', true],
        ];

        try {
            foreach ($commands as [$command, $projectScoped]) {
                $workspace = new CliWorkspace();
                if ($projectScoped) {
                    $workspace->writeProjectConfig();
                }

                $result = $workspace->run(['command' => $command], new FakeApiClient(), null, $projectScoped);

                self::assertSame(ExitCode::Usage->value, $result['code'], $command . ': brak poświadczenia to błąd użycia');
                self::assertStringContainsString('babelhub login', $result['stderr'], $command . ': komunikat ma dać drogę wyjścia');
                self::assertSame('', $result['stdout'], $command . ': brak wyniku, bo nie było o co pytać');

                $workspace->cleanup();
            }
        } finally {
            if (is_string($previous) && $previous !== '') {
                putenv('BABELHUB_TOKEN=' . $previous);
            }
        }
    }

    public function testASuccessfulCommandPrintsExactlyOneJsonDocumentOnStdout(): void
    {
        $this->workspace->writeProjectConfig();
        $this->workspace->writeCredentials();

        $body = ['application' => 'learnhub', 'keys' => ['total' => 3, 'removed' => 0], 'locales' => []];
        $client = (new FakeApiClient())->script('GET', '/api/v1/projects/learnhub/status', $body);

        $result = $this->workspace->run(['command' => 'status', '--output' => 'json'], $client);

        self::assertSame(ExitCode::Success->value, $result['code']);

        // Dokładnie jeden dokument: `json_decode` przepuszcza śmieci wokół obiektu tylko wtedy,
        // gdy ich nie ma — sprawdzamy więc i dekodowanie, i brak drugiej linii z treścią.
        $decoded = json_decode(trim($result['stdout']), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame('learnhub', $decoded['application'] ?? null, 'na stdout ma być ciało odpowiedzi serwisu');
        self::assertCount(1, array_filter(explode("\n", trim($result['stdout']))), 'jeden dokument, jedna linia');
    }

    public function testNothingInJsonModeGoesToStdoutFromDiagnostics(): void
    {
        $this->workspace->writeProjectConfig();
        $this->workspace->writeCredentials();
        $this->workspace->write('src/Keys.php', "<?php\n\ntrans('a.one');\n");

        // `--dry-run` nie wysyła niczego, więc wynik jest lokalny — a mimo to w trybie `json`
        // `stdout` musi zawierać **jeden** dokument, a diagnostyka (skan, brak pliku źródłowego)
        // musi iść na `stderr`. To jest cała treść obietnicy z 1.17.
        $result = $this->workspace->run(
            ['command' => 'push', '--dry-run' => true, '--output' => 'json'],
            new FakeApiClient(),
        );

        self::assertSame(ExitCode::Success->value, $result['code']);

        $decoded = json_decode(trim($result['stdout']), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($decoded['dry_run'] ?? null, 'wynikiem jest raport próby, a nie zdania dla człowieka');
        self::assertSame(1, $decoded['keys'] ?? null);

        self::assertStringNotContainsString('Skan:', $result['stdout'], 'diagnostyka nie wchodzi do JSON-a');
        self::assertStringNotContainsString('[dry-run]', $result['stdout'], 'ozdoba dla człowieka nie jest wynikiem');
        self::assertStringContainsString('Skan:', $result['stderr'], 'a jednak diagnostyka ma gdzieś być');
        self::assertStringContainsString(
            'Nie ma pliku źródłowego',
            $result['stderr'],
            'ostrzeżenie o brakującym pliku źródłowym to komunikat, nie wynik',
        );
    }

    public function testThePlainModePrintsNoOrnamentsOnStdout(): void
    {
        $this->workspace->writeProjectConfig();
        $this->workspace->writeCredentials();

        $client = (new FakeApiClient())->script('POST', '/api/v1/projects/learnhub/qa', [
            'pass' => false,
            'summary' => ['errors' => 1, 'warnings' => 0, 'info' => 0, 'total' => 1],
            'findings' => [[
                'code' => 'missing',
                'severity' => 'error',
                'key' => 'a.one',
                'locale' => 'pl_PL',
                'message' => 'Brak tłumaczenia.',
                'params' => [],
            ]],
        ]);

        $result = $this->workspace->run(['command' => 'validate', '--output' => 'plain'], $client);

        self::assertSame(ExitCode::CheckFailed->value, $result['code']);
        self::assertStringNotContainsString('<info>', $result['stdout'], 'plain jest do grep-a, nie do terminala');
        self::assertStringNotContainsString('<error>', $result['stdout']);
        self::assertStringContainsString('missing', $result['stdout'], 'po tym rozgałęzia się CI');
    }

    public function testAnUnknownOptionIsAUsageErrorAlsoWhenItComesFromARealCommandLine(): void
    {
        // **Ten test istnieje, bo live check pokazał kod `1` tam, gdzie test widział `2`.**
        // `ArrayInput` (używany przez resztę testów) rzuca przy nieznanej opcji
        // `InvalidArgumentException`, a `ArgvInput` — `Symfony\...\Exception\RuntimeException`.
        // Bez tej ścieżki „nie ma takiej opcji” wychodziłoby użytkownikowi jako awaria narzędzia.
        $this->workspace->writeProjectConfig();
        $this->workspace->writeCredentials();

        $result = $this->workspace->runArgv(
            ['babelhub', 'whoami', '--directory=' . $this->workspace->root()],
            new FakeApiClient(),
        );

        self::assertSame(ExitCode::Usage->value, $result['code']);
        self::assertStringContainsString('--directory', $result['stderr']);
        self::assertStringNotContainsString('Stack trace', $result['stderr']);
        self::assertSame('', $result['stdout']);
    }

    public function testEveryGlobalOptionIsAcceptedBeforeAndAfterTheCommandName(): void
    {
        // Kolejność flag to nie szczegół: `babelhub --timeout=5 status` i `babelhub status --timeout=5`
        // muszą działać tak samo, bo tak wygląda połowa wywołań w skryptach CI.
        $this->workspace->writeProjectConfig();
        $this->workspace->writeCredentials();

        foreach (
            [
                ['babelhub', '--timeout=5', 'status', '--directory=' . $this->workspace->root()],
                ['babelhub', 'status', '--timeout=5', '--directory=' . $this->workspace->root()],
            ] as $argv
        ) {
            $client = (new FakeApiClient())->script('GET', '/api/v1/projects/learnhub/status', ['ready' => true]);
            $result = $this->workspace->runArgv($argv, $client);

            self::assertSame(
                ExitCode::Success->value,
                $result['code'],
                implode(' ', $argv) . ': `--timeout` przed nazwą komendy musi działać tak samo jak po niej',
            );
        }
    }

    public function testTheExitCodeTableMatchesTheDocumentationForEveryStatusWeClaim(): void
    {
        // Kontrakt kodów jest jeden i wspólny dla całego CLI — sprawdzany tu na mapowaniu
        // (jedyne miejsce, w którym ono żyje), a nie na pojedynczej komendzie.
        $expected = [
            200 => ExitCode::Success,
            204 => ExitCode::Success,
            304 => ExitCode::Success,
            400 => ExitCode::Rejected,
            401 => ExitCode::Unauthorized,
            403 => ExitCode::Unauthorized,
            404 => ExitCode::Rejected,
            409 => ExitCode::Rejected,
            422 => ExitCode::Rejected,
            429 => ExitCode::RateLimited,
            500 => ExitCode::Failure,
            503 => ExitCode::Failure,
        ];

        foreach ($expected as $status => $code) {
            self::assertSame($code, (new ApiResponse($status, '{}'))->exitCode(), sprintf('HTTP %d', $status));
        }

        $documented = [
            ExitCode::Success->value,
            ExitCode::Failure->value,
            ExitCode::Usage->value,
            ExitCode::Unauthorized->value,
            ExitCode::RateLimited->value,
            ExitCode::Rejected->value,
            ExitCode::CheckFailed->value,
            ExitCode::Pending->value,
        ];
        $cliDocumentation = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/cli.md');

        foreach ($documented as $value) {
            self::assertStringContainsString(
                '`' . $value . '`',
                $cliDocumentation,
                sprintf('kod %d musi być w tabeli w docs/cli.md — inaczej tabela kłamie', $value),
            );
        }
    }

    public function testTheApplicationExposesEveryGlobalOptionThroughTheInputDefinition(): void
    {
        // Globalne opcje są dodane do definicji aplikacji, więc `--timeout` działa **przed**
        // nazwą komendy i po niej. Sprawdzamy to na wierszu poleceń, a nie na liście stałych:
        // opcja, której nie ma w definicji, kończy się kodem `1` z Symfony i komunikatem
        // „option does not exist”, czyli awarią, a nie błędem użycia.
        $result = $this->workspace->run(['command' => 'status', '-h' => true], new FakeApiClient());

        self::assertSame(ExitCode::Success->value, $result['code']);
        foreach (['--output', '--api-url', '--token', '--profile', '--timeout'] as $option) {
            self::assertStringContainsString($option, $result['stdout'], $option . ' musi być w pomocy');
        }
    }

    public function testTheTesterCanSeeTheTimeoutTheCommandBuiltTheClientWith(): void
    {
        // Zabezpieczenie samego testu: bez trzeciego argumentu fabryki powyższe asercje o
        // `--timeout` przechodziłyby przez przypadek (wartość domyślna z domknięcia).
        $captured = null;
        $factory = static function (string $url, ?string $token, float $timeout) use (&$captured): FakeApiClient {
            $captured = $timeout;

            return new FakeApiClient();
        };

        $this->workspace->writeProjectConfig();
        $this->workspace->writeCredentials();
        $client = (new FakeApiClient())->script('GET', '/api/v1/projects/learnhub/status', ['ready' => true]);

        $this->workspace->run(['command' => 'status', '--timeout' => '7.5'], $client, $factory);

        self::assertSame(7.5, $captured);
    }
}
