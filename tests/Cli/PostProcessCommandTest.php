<?php

declare(strict_types=1);

namespace Tests\Cli;

use BabelHub\Cli\Application\CliPull;
use BabelHub\Cli\Application\PostProcessRunner;
use BabelHub\Cli\Config\ProjectConfig;
use BabelHub\Cli\ExitCode;
use BabelHub\Cli\Output\OutputMode;
use BabelHub\Cli\Output\Printer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Cli\Support\CliWorkspace;
use Tests\Cli\Support\FakeApiClient;

/**
 * `download.post_process_command` — hook projektu po zapisaniu plików (pozycja 1.19 planu).
 *
 * Plan nazywa to „dociągnięciem formatowania własnym skryptem”. Ten plik pilnuje pięciu rzeczy,
 * z których każda jest powodem, dla którego taki hook da się w ogóle wpiąć w CI:
 *
 * 1. **Uruchamia się po zapisaniu plików** i widzi je na dysku (bo po to jest).
 * 2. **Nie uruchamia się, gdy nic nie zapisano** — przy samych `304` nie ma czego formatować,
 *    a `prettier` przepisujący pliki własnym stylem to diff z niczego.
 * 3. **Niepowodzenie hook-a nie udaje nieudanego pobrania.** Pliki są zapisane, lock jest
 *    aktualny (inaczej następny `pull` pobierałby wszystko od nowa), a kod wyjścia mówi
 *    „narzędzie nie dokończyło pracy” — nie „tłumaczenia są złe”.
 * 4. **Wyjście hook-a jest widoczne przy błędzie** — tam stoi jedyne zdanie z powodem
 *    (`command not found`, brak zależności, zły glob).
 * 5. **Skrypt, który wisi, jest ubijany.** Hook czekający na wejście z terminala wiesza pipeline
 *    **na zawsze**, a takiej awarii nie widać w logu — dlatego limit jest częścią kontraktu
 *    (`124` to konwencja `timeout(1)`).
 */
final class PostProcessCommandTest extends TestCase
{
    private const PROJECT_PATH = '/api/v1/projects/learnhub';
    private const CATALOG_PATH = self::PROJECT_PATH . '/translations/pl_PL';

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

    public function testTheHookRunsAfterTheFilesAreWritten(): void
    {
        $client = $this->scriptCatalog($this->client(), 7, ['a.one' => 'One']);

        // Fixture przez `php -r`, a nie `test … && touch …`: hook idzie przez **powłokę systemu**,
        // więc na Windowsie `proc_open` daje `cmd /c`, gdzie `test` i `touch` nie istnieją.
        // Test ma sprawdzać kontrakt hook-a (idzie po zapisie, widzi pliki, zwraca kod), a nie
        // to, że czyjś shell jest POSIX-owy.
        $command = $this->php(
            "exit(is_file('translations/messages.pl_PL.php') && filesize('translations/messages.pl_PL.php') > 0"
            . " && touch('hook-ran') ? 0 : 1);",
        );

        $result = $this->runPull($client, $command);

        self::assertSame(ExitCode::Success->value, $result['code']);
        self::assertFileExists(
            $this->workspace->path('hook-ran'),
            'hook widzi pliki już zapisane — inaczej nie miałby czego formatować',
        );
        self::assertStringContainsString('Post-process:', $result['stderr'], 'log mówi, że hook w ogóle poszedł');
    }

    public function testTheJsonResultSaysWhetherTheHookRan(): void
    {
        $client = $this->scriptCatalog($this->client(), 7, ['a.one' => 'One']);

        $command = $this->php('exit(0);');
        $payload = $this->runPullJson($client, $command);

        self::assertSame(
            ['command' => $command, 'ran' => true, 'exit_code' => 0, 'timed_out' => false],
            $payload['post_process'],
            'wynik maszynowy mówi, czy hook w ogóle poszedł i z jakim kodem — CI musi to umieć odróżnić',
        );
    }

    public function testTheHookIsSkippedWhenNothingWasWritten(): void
    {
        // Pierwsze pobranie pisze plik, drugie dostaje `304` — czyli nie ma czego zapisywać.
        // Odpowiedzi są **skryptowane po kolei**: fałszywy klient powtarza ostatnią, więc `304`
        // musi być podane jawnie (inaczej test mierzyłby własne założenie o `If-None-Match`).
        $client = $this->scriptCatalog($this->client(), 7, ['a.one' => 'One']);
        $client->scriptRaw('GET', self::CATALOG_PATH, 304, '');

        $command = $this->php("exit(touch('hook-ran') ? 0 : 1);");

        $first = $this->runPull($client, $command);
        self::assertSame(ExitCode::Success->value, $first['code']);
        self::assertFileExists($this->workspace->path('hook-ran'));

        $this->workspace->remove('hook-ran');
        $second = $this->runPull($client, $command);

        self::assertSame(ExitCode::Success->value, $second['code']);
        self::assertStringContainsString('bez zmian', $second['stdout']);
        self::assertFileDoesNotExist(
            $this->workspace->path('hook-ran'),
            'przy `304` hook nie idzie — `prettier` na niezmienionym pliku to diff z niczego',
        );
    }

    public function testAFailingHookKeepsTheFilesAndTheLockButFailsTheCommand(): void
    {
        $client = $this->scriptCatalog($this->client(), 9, ['a.one' => 'One', 'b.two' => 'Two']);

        $result = $this->runPull(
            $client,
            $this->php("fwrite(STDERR, 'formatter nie ma konfiguracji'); exit(3);"),
        );

        self::assertSame(
            ExitCode::Failure->value,
            $result['code'],
            'kod 1: narzędzie nie dokończyło pracy — to nie „tłumaczenia są złe” (6) ani „popraw wywołanie” (2)',
        );
        self::assertStringContainsString('zwrócił kod 3', $result['stderr']);
        self::assertStringContainsString('formatter nie ma konfiguracji', $result['stderr'], 'wyjście hook-a to jedyne miejsce z powodem');
        self::assertStringContainsString('NIE zostały przetworzone', $result['stderr']);

        $file = $this->workspace->path('translations/messages.pl_PL.php');
        self::assertFileExists($file, 'pliki są zapisane — hook jest krokiem PO pobraniu, nie zamiast niego');
        self::assertNotNull(
            $this->workspace->loadLock(),
            'lock musi być zapisany: inaczej następny pull pobierałby wszystko od nowa, mimo że pliki są na miejscu',
        );
    }

    public function testAHookThatHangsIsKilledInsteadOfHangingThePipeline(): void
    {
        $client = $this->scriptCatalog($this->client(), 11, ['a.one' => 'One']);

        // `sleep 30` nie istnieje w `cmd`, a `php -r 'sleep(30);'` wisi na obu systemach.
        $started = microtime(true);
        $result = $this->runPull($client, $this->php('sleep(30);'), timeoutSeconds: 1);
        $elapsed = microtime(true) - $started;

        self::assertSame(ExitCode::Failure->value, $result['code']);
        self::assertStringContainsString('przekroczył limit 1 s', $result['stderr']);
        self::assertStringContainsString('NIE przetworzone', $result['stderr']);

        // **Czas jest tu asercją, nie szczegółem.** Sam komunikat i kod wyjścia przechodziły
        // także wtedy, gdy CLI czekało na hook do końca jego pracy — na Windows 30,2 s przy
        // limicie 1 s, bo `cmd /c` zostawał rodzicem żywego dziecka i trzymał potoki otwarte.
        // Kontrakt brzmi „limit”, więc mierzymy limit: próg 10 s jest 10× luźniejszy od
        // oczekiwanego ~1 s, a i tak trzy razy ostrzejszy od zachowania, które było wadą.
        self::assertLessThan(
            10.0,
            $elapsed,
            sprintf('hook z limitem 1 s zajął %.1f s — limit nie ubija drzewa procesów', $elapsed),
        );
    }

    /**
     * Komenda hook-a w wersji **przenośnej**: `php -r` zamiast `sh`-owych `test`/`touch`/`sleep`.
     *
     * Hook uruchamiamy przez powłokę projektu (umowa: ta sama kategoria zaufania co
     * `composer.json` → `scripts`), więc fixture nie może zakładać, że tą powłoką jest `sh`:
     * na Windowsie `proc_open` daje `cmd /c`, gdzie `test`, `touch` i `sleep` nie istnieją.
     * `PHP_BINARY` jest znany testowi, a komenda **nadal przechodzi przez powłokę** — czyli test
     * mierzy to, co ma mierzyć, tylko na obu systemach.
     */
    private function php(string $code): string
    {
        return escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code);
    }

    private function client(): FakeApiClient
    {
        return (new FakeApiClient(CliWorkspace::API_URL, CliWorkspace::TOKEN))
            ->script('GET', '/api/v1/me', ['organization' => ['slug' => 'acme']]);
    }

    /** @param array<string, string> $messages */
    private function scriptCatalog(FakeApiClient $client, int $version, array $messages): FakeApiClient
    {
        $client->script('GET', self::PROJECT_PATH, [
            'project' => [
                'slug' => CliWorkspace::PROJECT,
                'name' => 'QA demo',
                'source_locale' => 'en_GB',
                'locales' => ['pl_PL'],
                'is_active' => true,
            ],
        ]);

        return $client->script('GET', self::CATALOG_PATH, [
            'application' => CliWorkspace::PROJECT,
            'locale' => 'pl_PL',
            'version' => $version,
            'translations' => $messages,
        ]);
    }

    /**
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function runPull(
        FakeApiClient $client,
        ?string $command,
        int $timeoutSeconds = PostProcessRunner::TIMEOUT_SECONDS,
    ): array {
        $stdout = new BufferedOutput();
        $stderr = new BufferedOutput();

        $code = (new CliPull(
            $client,
            new Printer($stdout, $stderr, OutputMode::Text),
            new PostProcessRunner($timeoutSeconds),
        ))->run($this->config($command), $this->workspace->root(), false);

        return ['code' => $code->value, 'stdout' => $stdout->fetch(), 'stderr' => $stderr->fetch()];
    }

    /** @return array<string, mixed> */
    private function runPullJson(FakeApiClient $client, ?string $command): array
    {
        $stdout = new BufferedOutput();
        $stderr = new BufferedOutput();

        (new CliPull(
            $client,
            new Printer($stdout, $stderr, OutputMode::Json),
            new PostProcessRunner(),
        ))->run($this->config($command), $this->workspace->root(), false);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(trim($stdout->fetch()), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function config(?string $command): ProjectConfig
    {
        return new ProjectConfig(
            project: CliWorkspace::PROJECT,
            sourceLocale: 'en_GB',
            filePattern: 'translations/messages.{lang}.php',
            fileFormat: ProjectConfig::FORMAT_PHP,
            scanPaths: ['src'],
            downloadPostProcessCommand: $command,
        );
    }
}
