<?php

declare(strict_types=1);

namespace Tests\Cli;

use BabelHub\Cli\Config\CredentialsStore;
use BabelHub\Cli\Application\CliLogin;
use BabelHub\Cli\ExitCode;
use BabelHub\Cli\Config\PendingPairingStore;
use BabelHub\Cli\Output\OutputMode;
use BabelHub\Cli\Output\Printer;
use BabelHub\Cli\Config\PendingPairing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Cli\Support\FakeApiClient;
use Tests\Cli\Support\FakeSleeper;
use Tests\Cli\Support\RecordingBrowser;

/**
 * Przepływ logowania CLI: kod wyjścia, pliki i to, co trafia na `stdout`, a co na `stderr`.
 *
 * Ten test jest odpowiedzią na pozycję 1.16 planu: u konkurencji `download` potrafi wyjść
 * kodem `0` mimo błędu, `--version` kłamie, a domyślny timeout sięga setek sekund. Dlatego
 * sprawdzamy **zachowanie**, a nie dokumentację: że `--token`, który nie działa, **nie** zostaje
 * zapisany; że pętla odpytywania wychodzi po odmowie; że `device_code` nie opuszcza maszyny;
 * i że wynik idzie na `stdout`, a komunikaty na `stderr` (bo inaczej `json` i `plain`
 * przestają nadawać się do parsowania).
 */
final class CliLoginTest extends TestCase
{
    private string $home;
    private FakeApiClient $client;
    private FakeSleeper $sleeper;
    private RecordingBrowser $browser;
    private BufferedOutput $stdout;
    private BufferedOutput $stderr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->home = sys_get_temp_dir() . '/babelhub-cli-test-' . bin2hex(random_bytes(6));
        mkdir($this->home, 0700, true);

        $this->client = new FakeApiClient('https://babelhub.example');
        $this->sleeper = new FakeSleeper();
        $this->browser = new RecordingBrowser();
        $this->stdout = new BufferedOutput();
        $this->stderr = new BufferedOutput();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->home);

        parent::tearDown();
    }

    public function testABrokenTokenIsRejectedAndNotSaved(): void
    {
        $this->client->script('GET', '/api/v1/me', ['error' => 'unauthorized', 'message' => 'Token nieprawidłowy.'], 401);

        $code = $this->login()->withToken('pat_zły.sekret');

        self::assertSame(ExitCode::Unauthorized, $code, '401 z API to kod 3, a nie ogólne „coś się nie udało”');
        self::assertFileDoesNotExist($this->credentialsPath(), 'nieudane poświadczenie nie może wylądować na dysku');
        self::assertStringContainsString('Token nieprawidłowy.', $this->stderr->fetch(), 'człowiek ma przeczytać zdanie serwisu, nie „HTTP 401”');
    }

    public function testAWorkingTokenIsVerifiedBeforeBeingSaved(): void
    {
        $this->client->script('GET', '/api/v1/me', [
            'authenticated_as' => 'user',
            'organization' => ['name' => 'Acme', 'slug' => 'acme'],
            'scopes' => ['translations:read'],
            'user' => ['username' => 'admin', 'display_name' => 'Mikołaj'],
        ]);

        $code = $this->login()->withToken('pat_dobry.sekret');

        self::assertSame(ExitCode::Success, $code);
        self::assertFileExists($this->credentialsPath());
        self::assertSame('Acme', $this->storedCredentials()['organization']);
        self::assertSame('pat_dobry.sekret', $this->storedCredentials()['token']);
    }

    public function testStartingPairingWithoutWaitingPrintsJsonOnStdoutAndKeepsTheDeviceCodeLocal(): void
    {
        $this->client->script('POST', '/api/v1/cli/device', [
            'device_code' => 'sekret-terminala',
            'user_code' => 'BCDF-2345',
            'verification_uri' => 'https://babelhub.example/cli/authorize',
            'verification_uri_complete' => 'https://babelhub.example/cli/authorize?code=BCDF-2345',
            'expires_in' => 600,
            'interval' => 5,
        ], 201);

        $code = $this->login(OutputMode::Json)->startPairing(openBrowser: false, wait: false);

        self::assertSame(ExitCode::Pending, $code, 'brak poświadczenia to nie sukces — kod 7 mówi „dokończ w przeglądarce”');

        $payload = json_decode(trim($this->stdout->fetch()), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('BCDF-2345', $payload['verification_code']);
        self::assertArrayHasKey('next_step', $payload);
        self::assertStringNotContainsString(
            'sekret-terminala',
            $payload['next_step'],
            'device_code nie może trafić do polecenia, które człowiek wklei — inaczej wycieka do historii powłoki',
        );

        $pending = (new PendingPairingStore($this->home))->load();
        self::assertNotNull($pending, 'stan parowania musi zostać lokalnie, żeby --complete miał czym odebrać token');
        self::assertSame('sekret-terminala', $pending->deviceCode);
    }

    public function testCompletingPairingAfterApprovalSavesTheCredentialAndClearsThePendingState(): void
    {
        $this->writePending('sekret-terminala', 'BCDF-2345');
        $this->client->script('POST', '/api/v1/cli/token', [
            'status' => 'ready',
            'token' => 'pat_nowy.sekret',
            'token_type' => 'Bearer',
            'scopes' => ['translations:read'],
            'organization' => ['name' => 'Acme', 'slug' => 'acme'],
            'user' => ['username' => 'admin', 'display_name' => 'Mikołaj'],
        ]);

        $code = $this->login()->completePairing('https://babelhub.example/cli/authorize?code=BCDF-2345');

        self::assertSame(ExitCode::Success, $code);
        self::assertSame('pat_nowy.sekret', $this->storedCredentials()['token']);
        self::assertFileDoesNotExist(
            $this->home . '/pending-pairing.json',
            'sekret terminala nie może zostać na dysku po odebraniu poświadczenia',
        );
    }

    public function testCompletingWithADifferentCodeThanTheLocalOneIsRefused(): void
    {
        $this->writePending('sekret-terminala', 'BCDF-2345');

        $code = $this->login()->completePairing('https://babelhub.example/cli/authorize?code=ZZZZ-9999');

        self::assertSame(ExitCode::Usage, $code);
        self::assertSame(0, $this->client->callCount('POST', '/api/v1/cli/token'), 'nie ma po co pytać serwisu o cudzy kod');
        self::assertFileDoesNotExist($this->credentialsPath());
    }

    public function testDeniedPairingEndsWithAnAuthenticationExitCodeAndNoCredential(): void
    {
        $this->writePending('sekret-terminala', 'BCDF-2345');
        $this->client->script('POST', '/api/v1/cli/token', ['status' => 'denied']);

        $code = $this->login()->completePairing('https://babelhub.example/cli/authorize?code=BCDF-2345');

        self::assertSame(ExitCode::Unauthorized, $code);
        self::assertFileDoesNotExist($this->credentialsPath());
        self::assertStringContainsString('odrzucone', $this->stderr->fetch());
    }

    public function testThePollingLoopWaitsBetweenRequestsAndStopsOnApproval(): void
    {
        $this->writePending('sekret-terminala', 'BCDF-2345');
        $this->client
            ->script('POST', '/api/v1/cli/token', ['status' => 'pending', 'interval' => 5])
            ->script('POST', '/api/v1/cli/token', ['status' => 'pending', 'interval' => 5])
            ->script('POST', '/api/v1/cli/token', [
                'status' => 'ready',
                'token' => 'pat_nowy.sekret',
                'organization' => ['name' => 'Acme', 'slug' => 'acme'],
                'user' => ['display_name' => 'Mikołaj'],
            ]);

        $code = $this->login()->completePairing('https://babelhub.example/cli/authorize?code=BCDF-2345');

        self::assertSame(ExitCode::Success, $code);
        self::assertSame(3, $this->client->callCount('POST', '/api/v1/cli/token'));
        self::assertSame(2, $this->sleeper->calls, 'między odpytaniami trzeba czekać — inaczej CLI zasypuje serwis');
    }

    public function testAnExpiredPairingDoesNotCreateACredential(): void
    {
        $this->writePending('sekret-terminala', 'BCDF-2345');
        $this->client->script('POST', '/api/v1/cli/token', ['status' => 'expired']);

        $code = $this->login()->completePairing('https://babelhub.example/cli/authorize?code=BCDF-2345');

        self::assertSame(ExitCode::Unauthorized, $code);
        self::assertFileDoesNotExist($this->credentialsPath());
    }

    public function testCompletingWithoutAStartedPairingIsAUsageErrorNotACrash(): void
    {
        $code = $this->login()->completePairing('https://babelhub.example/cli/authorize?code=BCDF-2345');

        self::assertSame(ExitCode::Usage, $code);
        self::assertStringContainsString('Nie ma zaczętego parowania', $this->stderr->fetch());
    }

    public function testTheStderrCarriesNoJsonWhenTheModeIsJson(): void
    {
        $this->client->script('POST', '/api/v1/cli/device', [
            'device_code' => 'sekret-terminala',
            'user_code' => 'BCDF-2345',
            'verification_uri_complete' => 'https://babelhub.example/cli/authorize?code=BCDF-2345',
            'expires_in' => 600,
        ], 201);

        $this->login(OutputMode::Json)->startPairing(openBrowser: false, wait: false);

        $stdout = trim($this->stdout->fetch());
        self::assertNotSame('', $stdout);
        // Na `stdout` dokładnie jeden obiekt JSON — to kontrakt trybu `json` (1.17).
        self::assertIsArray(json_decode($stdout, true, 512, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('{', $this->stderr->fetch(), 'komunikaty dla człowieka nie mogą udawać JSON-a');
    }

    private function login(OutputMode $mode = OutputMode::Text): CliLogin
    {
        return new CliLogin(
            client: $this->client,
            store: new CredentialsStore($this->credentialsPath()),
            pending: new PendingPairingStore($this->home),
            printer: new Printer($this->stdout, $this->stderr, $mode),
            browser: $this->browser,
            sleeper: $this->sleeper,
        );
    }

    private function credentialsPath(): string
    {
        return $this->home . '/credentials.json';
    }

    /** @return array<string, mixed> */
    private function storedCredentials(): array
    {
        $decoded = json_decode((string) file_get_contents($this->credentialsPath()), true, 512, JSON_THROW_ON_ERROR);

        return $decoded['profiles']['default'];
    }

    private function writePending(string $deviceCode, string $userCode): void
    {
        (new PendingPairingStore($this->home))->save(new PendingPairing(
            deviceCode: $deviceCode,
            userCode: $userCode,
            browserUrl: 'https://babelhub.example/cli/authorize?code=' . $userCode,
            apiUrl: 'https://babelhub.example',
            expiresAt: new \DateTimeImmutable('+10 minutes'),
        ));
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
