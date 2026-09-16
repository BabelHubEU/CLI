<?php

declare(strict_types=1);

namespace Tests\Cli;

use BabelHub\Cli\Config\CredentialsStore;
use BabelHub\Cli\Config\ProjectConfig;
use BabelHub\Cli\Config\ProjectConfigStore;
use BabelHub\Cli\Console\Application;
use BabelHub\Cli\ExitCode;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Cli\Support\FakeApiClient;

/**
 * `babelhub init` — jedyny moment, w którym CLI **pisze** coś do repozytorium.
 *
 * Kolejność kroków jest tu produktem, nie szczegółem: projekt sprawdzamy w serwisie **przed**
 * zapisaniem pliku (literówka w slugu nie może czekać na pierwszy `push`, żeby wyjść jako `404`),
 * brak poświadczenia to zdanie z drogą wyjścia, a bez terminala polecenie **odmawia** zamiast
 * zgadywać — konfiguracja wybrana losowo jest gorsza niż jej brak, bo wysłałaby kod jednego
 * projektu do drugiego. Każdy z tych kroków kończy się kodem `2` i **żadnym plikiem na dysku**.
 */
final class InitCommandTest extends TestCase
{
    private const API_URL = 'https://babelhub.example';
    private const PROJECTS_PATH = '/api/v1/projects';

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/babelhub-cli-test-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0777, true);

        unset($_ENV[CredentialsStore::ENV_TOKEN], $_ENV[CredentialsStore::ENV_API_URL]);
        putenv(CredentialsStore::ENV_TOKEN);
        putenv(CredentialsStore::ENV_API_URL);
    }

    protected function tearDown(): void
    {
        unset($_ENV[CredentialsStore::ENV_TOKEN], $_ENV[CredentialsStore::ENV_API_URL]);
        putenv(CredentialsStore::ENV_TOKEN);
        putenv(CredentialsStore::ENV_API_URL);
        $this->removeDirectory($this->directory);

        parent::tearDown();
    }

    public function testItWritesTheConfigurationWithTheProjectAndTheSourceLocale(): void
    {
        mkdir($this->directory . '/src', 0777, true);
        mkdir($this->directory . '/templates', 0777, true);
        $client = $this->client()->script('GET', self::PROJECTS_PATH, ['projects' => [$this->project()]]);

        $result = $this->runInit(['--project' => 'learnhub', '--source-locale' => 'pl_PL'], $client);

        self::assertSame(ExitCode::Success->value, $result['code']);
        self::assertFileExists($this->configPath());
        self::assertSame(1, $client->callCount('GET', self::PROJECTS_PATH), 'slug sprawdzamy w serwisie przed zapisem pliku');

        $config = $this->writtenConfig();
        self::assertSame('learnhub', $config['project']);
        self::assertSame('pl_PL', $config['source_locale']);
        self::assertSame(
            ['pattern' => 'translations/messages.{lang}.php', 'format' => ProjectConfig::FORMAT_PHP],
            $config['files'],
        );
        self::assertSame(
            ['paths' => ['src', 'templates']],
            $config['scan'],
            'domyślne katalogi dopasowują się do projektu: brak `config/` nie może przewrócić pierwszego skanu',
        );

        self::assertStringContainsString('Zapisano ' . $this->configPath(), $result['stdout']);
        self::assertStringContainsString('projekt:        learnhub (LearnHub)', $result['stdout']);
        self::assertStringContainsString('język źródłowy: pl_PL', $result['stdout']);
        self::assertStringContainsString('skan:           src, templates', $result['stdout']);
    }

    public function testTheSourceLocaleDefaultsToTheProjectSourceWhenTheFlagIsMissing(): void
    {
        $client = $this->client()->script('GET', self::PROJECTS_PATH, ['projects' => [$this->project()]]);

        $result = $this->runInit(['--project' => 'learnhub'], $client);

        self::assertSame(ExitCode::Success->value, $result['code']);
        self::assertSame('en_GB', $this->writtenConfig()['source_locale'] ?? null);
    }

    public function testWithoutAnyKnownDirectoryTheWholeProjectIsScanned(): void
    {
        // Skanner i tak pomija `vendor`, `node_modules` i cache — lepsze to niż zerowa partia.
        $client = $this->client()->script('GET', self::PROJECTS_PATH, ['projects' => [$this->project()]]);

        $result = $this->runInit(['--project' => 'learnhub', '--source-locale' => 'en_GB'], $client);

        self::assertSame(ExitCode::Success->value, $result['code']);
        self::assertSame(['.'], $this->writtenConfig()['scan']['paths'] ?? null);
    }

    public function testAnUnknownProjectSlugIsRejectedAndNothingIsWritten(): void
    {
        $client = $this->client()->script('GET', self::PROJECTS_PATH, ['projects' => [$this->project()]]);

        $result = $this->runInit(['--project' => 'learnub', '--source-locale' => 'en_GB'], $client);

        self::assertSame(ExitCode::Usage->value, $result['code']);
        self::assertFileDoesNotExist($this->configPath(), 'konfiguracja z literówką w slugu jest gorsza niż jej brak');
        self::assertStringContainsString('learnub', $result['stderr']);
        self::assertStringContainsString(
            'Dostępne: learnhub',
            $result['stderr'],
            'komunikat ma podać, co jest dostępne — inaczej człowiek zgaduje drugi raz',
        );
    }

    public function testASourceLocaleTheProjectDoesNotHaveIsRejected(): void
    {
        $client = $this->client()->script('GET', self::PROJECTS_PATH, ['projects' => [$this->project()]]);

        $result = $this->runInit(['--project' => 'learnhub', '--source-locale' => 'de_DE'], $client);

        self::assertSame(ExitCode::Usage->value, $result['code']);
        self::assertFileDoesNotExist($this->configPath());
        self::assertStringContainsString('de_DE', $result['stderr']);
        self::assertStringContainsString('en_GB, pl_PL', $result['stderr'], 'komunikat ma wypisać języki projektu');
    }

    public function testAnExistingFileIsNotOverwrittenWithoutForce(): void
    {
        file_put_contents($this->configPath(), "{}\n");
        $client = $this->client();

        $result = $this->runInit(['--project' => 'learnhub', '--source-locale' => 'en_GB'], $client);

        self::assertSame(ExitCode::Usage->value, $result['code']);
        self::assertSame("{}\n", (string) file_get_contents($this->configPath()), 'istniejący plik zostaje nietknięty');
        self::assertStringContainsString('już istnieje', $result['stderr']);
        self::assertStringContainsString('--force', $result['stderr']);
        self::assertSame([], $client->requests, 'sprawdzamy plik, zanim zapytamy serwis o cokolwiek');
    }

    public function testForceOverwritesTheExistingFile(): void
    {
        (new ProjectConfigStore($this->directory))->save(new ProjectConfig(
            project: 'stary.projekt',
            sourceLocale: 'de_DE',
            filePattern: 'translations/messages.{lang}.php',
            fileFormat: ProjectConfig::FORMAT_PHP,
            scanPaths: ['src'],
        ), $this->directory);
        $client = $this->client()->script('GET', self::PROJECTS_PATH, ['projects' => [$this->project()]]);

        $result = $this->runInit(['--project' => 'learnhub', '--source-locale' => 'en_GB', '--force' => true], $client);

        self::assertSame(ExitCode::Success->value, $result['code']);
        self::assertSame('learnhub', $this->writtenConfig()['project'] ?? null);
        self::assertFileDoesNotExist($this->configPath() . '.tmp');
    }

    public function testWithoutCredentialsItSaysHowToGetThem(): void
    {
        $result = $this->runInit(
            ['--project' => 'learnhub', '--source-locale' => 'en_GB'],
            $this->client(),
            withCredentials: false,
        );

        self::assertSame(ExitCode::Usage->value, $result['code']);
        self::assertFileDoesNotExist($this->configPath());
        self::assertStringContainsString('babelhub login', $result['stderr'], 'brak poświadczenia to zdanie z drogą wyjścia, nie samo „błąd”');
    }

    public function testWithoutATerminalItRefusesToGuessTheProject(): void
    {
        $client = $this->client()->script('GET', self::PROJECTS_PATH, ['projects' => [$this->project()]]);

        $result = $this->runInit(['--source-locale' => 'en_GB'], $client, interactive: false);

        self::assertSame(ExitCode::Usage->value, $result['code']);
        self::assertFileDoesNotExist($this->configPath());
        self::assertStringContainsString('--project=<slug>', $result['stderr']);
    }

    public function testWithoutATerminalItRefusesToGuessTheSourceLocale(): void
    {
        $client = $this->client()->script('GET', self::PROJECTS_PATH, [
            'projects' => [['slug' => 'learnhub', 'name' => 'LearnHub', 'locales' => ['en_GB', 'pl_PL']]],
        ]);

        $result = $this->runInit(['--project' => 'learnhub'], $client, interactive: false);

        self::assertSame(ExitCode::Usage->value, $result['code']);
        self::assertFileDoesNotExist($this->configPath());
        self::assertStringContainsString('--source-locale=<kod>', $result['stderr']);
    }

    public function testAProjectWithNoLocalesAtAllSaysWhereToAddThem(): void
    {
        $client = $this->client()->script('GET', self::PROJECTS_PATH, [
            'projects' => [['slug' => 'learnhub', 'name' => 'LearnHub', 'locales' => []]],
        ]);

        $result = $this->runInit(['--project' => 'learnhub'], $client, interactive: false);

        self::assertSame(ExitCode::Usage->value, $result['code']);
        self::assertStringContainsString('Języki projektu', $result['stderr']);
    }

    public function testAnUnauthorizedAnswerGivesTheUnauthorizedCodeAndWritesNothing(): void
    {
        $client = $this->client()->script(
            'GET',
            self::PROJECTS_PATH,
            ['error' => 'unauthorized', 'message' => 'Token wygasł.'],
            401,
        );

        $result = $this->runInit(['--project' => 'learnhub', '--source-locale' => 'en_GB'], $client);

        // Uwaga: `fetchProjects()` spłaszcza **każdą** nieudaną odpowiedź do `null`, więc ten sam
        // kod 3 wychodzi także przy 429 i 500 — to zgłoszony błąd, nie wzorzec do naśladowania.
        self::assertSame(ExitCode::Unauthorized->value, $result['code']);
        self::assertFileDoesNotExist($this->configPath());
    }

    public function testAPatternWithoutTheLanguagePlaceholderIsRejectedBeforeWriting(): void
    {
        $client = $this->client()->script('GET', self::PROJECTS_PATH, ['projects' => [$this->project()]]);

        $result = $this->runInit([
            '--project' => 'learnhub',
            '--source-locale' => 'en_GB',
            '--pattern' => 'translations/messages.php',
        ], $client);

        self::assertSame(ExitCode::Usage->value, $result['code']);
        self::assertFileDoesNotExist($this->configPath(), 'niepoprawna konfiguracja nie może wylądować na dysku');
        self::assertStringContainsString('files.pattern', $result['stderr']);
    }

    public function testTheFormatAndThePatternCanBeChosenExplicitly(): void
    {
        $client = $this->client()->script('GET', self::PROJECTS_PATH, ['projects' => [$this->project()]]);

        $result = $this->runInit([
            '--project' => 'learnhub',
            '--source-locale' => 'en_GB',
            '--pattern' => 'lang/{lang}/messages.json',
            '--format' => ProjectConfig::FORMAT_JSON,
        ], $client);

        self::assertSame(ExitCode::Success->value, $result['code']);
        self::assertSame(
            ['pattern' => 'lang/{lang}/messages.json', 'format' => ProjectConfig::FORMAT_JSON],
            $this->writtenConfig()['files'] ?? null,
        );
    }

    public function testExplicitScanPathsAreLeftUntouchedEvenWhenTheDirectoryDoesNotExist(): void
    {
        // Domyślne katalogi dopasowujemy do projektu, ale ścieżka podana wprost jest **decyzją
        // człowieka** — literówka w niej ma być błędem pierwszego skanu, a nie cicho poprawiona.
        $client = $this->client()->script('GET', self::PROJECTS_PATH, ['projects' => [$this->project()]]);

        $result = $this->runInit([
            '--project' => 'learnhub',
            '--source-locale' => 'en_GB',
            '--scan-path' => ['lib', 'modules'],
        ], $client);

        self::assertSame(ExitCode::Success->value, $result['code']);
        self::assertSame(['paths' => ['lib', 'modules']], $this->writtenConfig()['scan'] ?? null);
    }

    /** @return array<string, mixed> */
    private function project(): array
    {
        return [
            'slug' => 'learnhub',
            'name' => 'LearnHub',
            'locales' => ['en_GB', 'pl_PL'],
            'source_locale' => 'en_GB',
        ];
    }

    private function client(): FakeApiClient
    {
        return new FakeApiClient(self::API_URL, 'pat_test.sekret');
    }

    /**
     * `init --config=yaml` pisze `.babelhub.yml` — format, w którym sekcje `upload`/`download`/`checks`
     * czyta się w code review jak dokument (pozycja 1.12).
     */
    public function testTheYamlConfigFormatCanBeChosenAndLoadsBackThroughTheStore(): void
    {
        mkdir($this->directory . '/src', 0777, true);
        $client = $this->client()->script('GET', self::PROJECTS_PATH, ['projects' => [$this->project()]]);

        $result = $this->runInit(['--project' => 'learnhub', '--source-locale' => 'en_GB', '--config' => 'yaml'], $client);

        self::assertSame(ExitCode::Success->value, $result['code'], $result['stderr']);
        $path = $this->directory . '/' . ProjectConfig::FILE_NAME_YAML;
        self::assertFileExists($path, 'wybrany format decyduje o nazwie pliku');
        self::assertFileDoesNotExist($this->configPath(), 'nie piszemy dwóch plików — byłyby dwa źródła prawdy');
        self::assertStringContainsString($path, $result['stdout']);

        $loaded = (new ProjectConfigStore($this->directory))->load();
        self::assertNotNull($loaded, 'to, co zapisaliśmy, musi się wczytać tym samym kodem co reszta CLI');
        self::assertSame('learnhub', $loaded->project);
        self::assertSame('en_GB', $loaded->sourceLocale);
        self::assertTrue($loaded->uploadMarkMissingAsRemoved, 'sekcje domyślne są w pliku widoczne, nie ukryte');
        self::assertSame(ProjectConfig::DEFAULT_FAIL_ON, $loaded->checksFailOn);
    }

    public function testAnUnknownConfigFormatIsAUsageError(): void
    {
        $client = $this->client();

        $result = $this->runInit(['--project' => 'learnhub', '--source-locale' => 'en_GB', '--config' => 'toml'], $client);

        self::assertSame(ExitCode::Usage->value, $result['code']);
        self::assertStringContainsString('--config', $result['stderr']);
        self::assertSame(0, $client->callCount('GET', self::PROJECTS_PATH), 'literówka w formacie nie ma po co iść do serwisu');
    }

    private function configPath(): string
    {
        return $this->directory . '/' . ProjectConfig::FILE_NAME;
    }

    /** @return array<string, mixed> */
    private function writtenConfig(): array
    {
        $decoded = json_decode((string) file_get_contents($this->configPath()), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, mixed> $arguments dodatkowe argumenty polecenia, jak z linii poleceń
     *
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function runInit(
        array $arguments,
        FakeApiClient $client,
        bool $withCredentials = true,
        bool $interactive = true,
    ): array {
        $stdout = new BufferedOutput();
        $stderr = new BufferedOutput();

        $input = new ArrayInput([
            'command' => 'init',
            '--directory' => $this->directory,
        ] + ($withCredentials ? ['--api-url' => self::API_URL, '--token' => 'pat_test.sekret'] : []) + $arguments);
        // Bez terminala Symfony i tak nie ma skąd wziąć odpowiedzi na pytanie wyboru.
        $input->setInteractive($interactive);

        $code = Application::run(
            credentialsPath: $this->directory . '/credentials.json',
            stdout: $stdout,
            stderr: $stderr,
            input: $input,
            clientFactory: static fn (string $url, ?string $token): FakeApiClient => $client,
        );

        return ['code' => $code, 'stdout' => $stdout->fetch(), 'stderr' => $stderr->fetch()];
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $path . '/' . $entry;
            is_dir($full) ? $this->removeDirectory($full) : @unlink($full);
        }

        @rmdir($path);
    }
}
