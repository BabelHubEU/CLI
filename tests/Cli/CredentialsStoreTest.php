<?php

declare(strict_types=1);

namespace Tests\Cli;

use BabelHub\Cli\Config\Credentials;
use BabelHub\Cli\Config\CredentialsStore;
use BabelHub\Cli\Console\Application;
use BabelHub\Cli\ExitCode;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Cli\Support\FakeApiClient;

/**
 * Poświadczenie CLI: **kolejność źródeł** i to, co dzieje się na dysku użytkownika.
 *
 * Dwie rzeczy są tu ważniejsze niż reszta. Pierwsza: kolejność `--token` → `BABELHUB_TOKEN` →
 * plik, bo w CI poświadczenie podaje się zmienną, a lokalnie plikiem — i pomyłka w tej
 * kolejności kończy się tym, że pipeline cicho pracuje na starym tokenie z laptopa.
 * Druga: prawa `0600` na pliku, bo to jedyne miejsce, gdzie w tym projekcie leży gotowy sekret.
 *
 * Testy komend przechodzą przez `Application::run()` z podstawionym wejściem i wyjściem —
 * czyli sprawdzają **to, co uruchamia człowiek**, a nie wewnętrzne API.
 */
final class CredentialsStoreTest extends TestCase
{
    private string $home;

    protected function setUp(): void
    {
        parent::setUp();

        $this->home = sys_get_temp_dir() . '/babelhub-creds-test-' . bin2hex(random_bytes(6));
        mkdir($this->home, 0700, true);

        unset($_ENV[CredentialsStore::ENV_TOKEN], $_ENV[CredentialsStore::ENV_API_URL]);
        putenv(CredentialsStore::ENV_TOKEN);
        putenv(CredentialsStore::ENV_API_URL);
    }

    protected function tearDown(): void
    {
        unset($_ENV[CredentialsStore::ENV_TOKEN], $_ENV[CredentialsStore::ENV_API_URL]);
        putenv(CredentialsStore::ENV_TOKEN);
        putenv(CredentialsStore::ENV_API_URL);
        $this->removeDirectory($this->home);

        parent::tearDown();
    }

    public function testTheFlagBeatsTheEnvironmentAndTheFile(): void
    {
        $store = $this->store();
        $store->save(new Credentials('https://file.example', 'pat_z_pliku'));
        $_ENV[CredentialsStore::ENV_TOKEN] = 'pat_ze_zmiennej';

        $resolved = $store->resolve(tokenFromFlag: 'pat_z_flagi');

        self::assertNotNull($resolved);
        self::assertSame('pat_z_flagi', $resolved->token);
        self::assertSame('https://file.example', $resolved->apiUrl, 'adres nadal pochodzi z pliku, skoro flaga go nie podała');
    }

    public function testTheEnvironmentBeatsTheFile(): void
    {
        $store = $this->store();
        $store->save(new Credentials('https://file.example', 'pat_z_pliku'));
        $_ENV[CredentialsStore::ENV_TOKEN] = 'pat_ze_zmiennej';

        self::assertSame('pat_ze_zmiennej', $store->resolve()?->token);
    }

    public function testWithoutAnySourceThereIsNothingToResolve(): void
    {
        self::assertNull($this->store()->resolve(), 'brak poświadczenia to brak — nie puste poświadczenie');
    }

    public function testTheCredentialFileIsNotReadableByOthers(): void
    {
        $path = $this->home . '/credentials.json';
        (new CredentialsStore($path))->save(new Credentials('https://babelhub.example', 'pat_sekret'));

        self::assertFileExists($path);
        // Windows nie ma praw POSIX, więc sprawdzamy to, co da się sprawdzić wszędzie:
        // plik nie jest czytelny dla „wszystkich” w modelu POSIX.
        $permissions = fileperms($path);
        if (PHP_OS_FAMILY !== 'Windows' && $permissions !== false) {
            self::assertSame(0600, $permissions & 0777, 'gotowy sekret nie może być czytelny dla innych kont');
        }

        self::assertStringContainsString('pat_sekret', (string) file_get_contents($path));
    }

    public function testForgettingOneProfileLeavesTheOther(): void
    {
        $store = $this->store();
        $store->save(new Credentials('https://prod.example', 'pat_prod'), 'prod');
        $store->save(new Credentials('https://dev.example', 'pat_dev'), 'default');

        self::assertTrue($store->forget('prod'));
        self::assertNull($store->load('prod'));
        self::assertNotNull($store->load('default'), 'wylogowanie z jednego serwisu nie może wylogowywać z drugiego');
    }

    public function testABrokenCredentialsFileSaysWhatToDoInsteadOfCrashing(): void
    {
        $path = $this->home . '/credentials.json';
        file_put_contents($path, '{to nie jest json');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Usuń go i zaloguj się ponownie/');

        (new CredentialsStore($path))->load();
    }

    public function testLogoutRemovesTheCredentialAndIsIdempotent(): void
    {
        $this->store()->save(new Credentials('https://babelhub.example', 'pat_sekret'));

        $first = $this->runCli(['command' => 'logout']);
        self::assertSame(ExitCode::Success->value, $first['code']);
        self::assertFileDoesNotExist($this->home . '/credentials.json');

        $second = $this->runCli(['command' => 'logout']);
        self::assertSame(
            ExitCode::Success->value,
            $second['code'],
            'drugie wylogowanie to nie błąd — skrypt sprzątający nie może się przewracać',
        );
        self::assertStringContainsString('Nie było czego kasować', $second['stderr']);
    }

    public function testLogoutWarnsThatAnEnvironmentTokenStillWorks(): void
    {
        $_ENV[CredentialsStore::ENV_TOKEN] = 'pat_z_ci';

        $result = $this->runCli(['command' => 'logout']);

        self::assertSame(ExitCode::Success->value, $result['code']);
        self::assertStringContainsString(
            CredentialsStore::ENV_TOKEN,
            $result['stderr'],
            'bez tego ostrzeżenia człowiek myśli, że wylogował CI, a token w zmiennej działa dalej',
        );
    }

    public function testWhoamiWithoutCredentialsIsAUsageErrorWithAHint(): void
    {
        $result = $this->runCli(['command' => 'whoami']);

        self::assertSame(ExitCode::Usage->value, $result['code']);
        self::assertStringContainsString('babelhub login', $result['stderr']);
    }

    public function testWhoamiPrintsTheIdentityAndRefreshesTheStoredMetadata(): void
    {
        $this->store()->save(new Credentials('https://babelhub.example', 'pat_sekret'));

        $client = (new FakeApiClient('https://babelhub.example'))->script('GET', '/api/v1/me', [
            'authenticated_as' => 'user',
            'organization' => ['name' => 'Acme', 'slug' => 'acme'],
            'scopes' => ['translations:read', 'qa:read'],
            'user' => ['username' => 'admin', 'display_name' => 'Mikołaj'],
            'token' => ['name' => 'laptop', 'created_at' => '2026-01-01T00:00:00+00:00', 'expires_at' => null],
        ]);

        $result = $this->runCli(['command' => 'whoami'], $client);

        self::assertSame(ExitCode::Success->value, $result['code']);
        self::assertStringContainsString('Acme', $result['stdout']);
        self::assertStringContainsString('translations:read', $result['stdout']);

        $stored = $this->store()->load();
        self::assertNotNull($stored);
        self::assertSame('Mikołaj', $stored->user, 'metadane poświadczenia odświeżają się przy okazji — jedno źródło prawdy');
        self::assertSame(['translations:read', 'qa:read'], $stored->scopes);
    }

    public function testWhoamiInJsonModePrintsOnlyJsonOnStdout(): void
    {
        $this->store()->save(new Credentials('https://babelhub.example', 'pat_sekret'));
        $client = (new FakeApiClient('https://babelhub.example'))->script('GET', '/api/v1/me', [
            'authenticated_as' => 'user',
            'organization' => ['name' => 'Acme', 'slug' => 'acme'],
            'scopes' => [],
            'user' => ['username' => 'admin', 'display_name' => 'Mikołaj'],
        ]);

        $result = $this->runCli(['command' => 'whoami', '--output' => 'json'], $client);

        self::assertSame(ExitCode::Success->value, $result['code']);
        $decoded = json_decode(trim($result['stdout']), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('user', $decoded['authenticated_as']);
    }

    public function testAnUnknownOutputModeIsRejectedInsteadOfFallingBackToText(): void
    {
        $this->store()->save(new Credentials('https://babelhub.example', 'pat_sekret'));

        $result = $this->runCli(['command' => 'whoami', '--output' => 'xml']);

        self::assertNotSame(
            ExitCode::Success->value,
            $result['code'],
            'skrypt parsujący JSON-a nie może dostać zdań dla człowieka i kodu 0',
        );
    }

    private function store(): CredentialsStore
    {
        return new CredentialsStore($this->home . '/credentials.json');
    }

    /**
     * @param array<string, mixed> $input argumenty polecenia, jak z linii poleceń
     *
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function runCli(array $input, ?FakeApiClient $client = null): array
    {
        $stdout = new BufferedOutput();
        $stderr = new BufferedOutput();

        $code = Application::run(
            credentialsPath: $this->home . '/credentials.json',
            stdout: $stdout,
            stderr: $stderr,
            input: new ArrayInput($input),
            clientFactory: static fn (string $url, ?string $token): FakeApiClient => $client ?? new FakeApiClient($url, $token),
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
