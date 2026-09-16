<?php

declare(strict_types=1);

namespace Tests\Cli;

use BabelHub\Cli\ExitCode;
use PHPUnit\Framework\TestCase;
use Tests\Cli\Support\CliWorkspace;
use Tests\Cli\Support\FakeApiClient;
use Tests\Cli\Support\RecordedRequests;

/**
 * `babelhub validate --check=hardcoded-strings` — kontrola, która czyta **kod, nie serwis**.
 *
 * Dwie rzeczy są tu kontraktem:
 *
 * * **brak żądania do API** — kontrola nie potrzebuje poświadczenia ani połączenia, więc działa
 *   w CI także przed `login` i nie zależy od dostępności serwisu. Test liczy żądania, bo
 *   „nie potrzebuje serwera” łatwo zamienić w „przypadkiem go woła”;
 * * **waga decyduje o bramce** — `hardcoded_string` jest ostrzeżeniem, więc domyślny próg
 *   (`error`) przepuszcza je, a `--fail-on=warning` blokuje. Fałszywy alarm blokujący merge
 *   uczy ludzi obchodzenia bramki, więc próg jest jawną decyzją, nie domysłem.
 */
final class HardcodedStringsCheckTest extends TestCase
{
    private CliWorkspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = new CliWorkspace();
        $this->workspace->writeProjectConfig();
        $this->workspace->writeCredentials();
        $this->workspace->write('src/Panel.php', "<?php\n\n\$title = 'Welcome back to the dashboard';\n");
    }

    protected function tearDown(): void
    {
        $this->workspace->cleanup();

        parent::tearDown();
    }

    public function testItReportsHardcodedTextWithoutAskingTheServer(): void
    {
        $client = new FakeApiClient();

        $result = $this->workspace->run(['command' => 'validate', '--check' => 'hardcoded-strings'], $client);

        self::assertSame(ExitCode::Success->value, $result['code'], 'ostrzeżenie nie blokuje domyślnej bramki');
        self::assertSame(0, (new RecordedRequests($client))->count(), 'kontrola kodu nie pyta serwisu');
        self::assertStringContainsString('src/Panel.php:3', $result['stdout']);
        self::assertStringContainsString('Welcome back to the dashboard', $result['stdout']);
        self::assertStringContainsString('ostrzeżeń: 1', $result['stdout'], 'podsumowanie z liczbami jest wynikiem');
    }

    /**
     * **Kontrola nie wymaga poświadczenia.** To jej przewaga nad pozostałymi (czyta kod, nie
     * serwis), więc w CI może stać przed `login` i działać, gdy serwis jest niedostępny.
     *
     * Ten test powstał po live checku: pierwsza wersja kodu rozwiązywała poświadczenie **przed**
     * rozgałęzieniem na tę kontrolę, więc w katalogu bez tokenu kończyło się „Brak poświadczenia”
     * (kod 2). Wszystkie ówczesne testy pisały poświadczenie w `setUp`, więc żaden tego nie
     * widział — czyli test mierzył własne założenie, a nie kontrakt.
     */
    public function testItWorksWithoutAnyCredential(): void
    {
        $workspace = new CliWorkspace();
        $workspace->writeProjectConfig();
        $workspace->write('src/Panel.php', "<?php\n\n\$title = 'Welcome back to the dashboard';\n");

        $previous = getenv('BABELHUB_TOKEN');
        putenv('BABELHUB_TOKEN');

        try {
            $result = $workspace->run(['command' => 'validate', '--check' => 'hardcoded-strings'], new FakeApiClient());
        } finally {
            if (is_string($previous) && $previous !== '') {
                putenv('BABELHUB_TOKEN=' . $previous);
            }

            $workspace->cleanup();
        }

        self::assertSame(ExitCode::Success->value, $result['code'], 'brak tokenu nie może blokować kontroli kodu');
        self::assertStringContainsString('src/Panel.php:3', $result['stdout']);
    }

    public function testTheWarningThresholdBlocksMerge(): void
    {
        $result = $this->workspace->run(
            ['command' => 'validate', '--check' => 'hardcoded-strings', '--fail-on' => 'warning'],
            new FakeApiClient(),
        );

        self::assertSame(ExitCode::CheckFailed->value, $result['code'], 'kod 6, bo to wynik kontroli, a nie awaria');
        self::assertStringContainsString('--fail-on=warning', $result['stderr']);
    }

    public function testTheConfigurationCanSetTheThresholdForThisCheck(): void
    {
        $this->workspace->writeProjectConfigYaml(failOn: 'warning');

        $result = $this->workspace->run(['command' => 'validate', '--check' => 'hardcoded-strings'], new FakeApiClient());

        self::assertSame(ExitCode::CheckFailed->value, $result['code'], '`checks.fail_on: warning` obowiązuje też tutaj');
    }

    public function testInJsonModeTheReportIsStructuredAndGoesToStdout(): void
    {
        $result = $this->workspace->run(
            ['command' => 'validate', '--check' => 'hardcoded-strings', '--output' => 'json'],
            new FakeApiClient(),
        );

        /** @var array<string, mixed> $body */
        $body = json_decode(trim($result['stdout']), true, 512, JSON_THROW_ON_ERROR);
        $check = $body['checks']['hardcoded-strings'];

        self::assertSame('hardcoded-strings', $check['check'], 'kontrole są w mapie po nazwie, a nie w liście');
        self::assertTrue($check['pass'], 'przy domyślnym progu ostrzeżenia nie blokują');
        self::assertSame(0, $check['blocking'], '`blocking` to liczba ustaleń, które zatrzymały bramkę przy tym progu');
        self::assertSame(1, $check['summary']['warnings']);
        self::assertSame(0, $check['summary']['errors']);
        self::assertSame('src/Panel.php:3', $check['findings'][0]['file'] . ':' . $check['findings'][0]['line']);
        self::assertSame([], $body['failures'], 'werdykt zbiorczy jest na wierzchu dokumentu');
    }

    public function testTheConfigurationCanSilenceALegacyDirectory(): void
    {
        $this->workspace->writeProjectConfigYaml(ignore: ['src']);
        $this->workspace->write('src/Panel.php', "<?php\n\n\$title = 'Welcome back to the dashboard';\n");

        $result = $this->workspace->run(['command' => 'validate', '--check' => 'hardcoded-strings'], new FakeApiClient());

        self::assertSame(ExitCode::Success->value, $result['code']);
        self::assertStringContainsString('ostrzeżeń: 0', $result['stdout'], 'wyjątek z konfiguracji obowiązuje');
    }

    public function testAnUnknownCheckNameIsAUsageErrorBeforeAnyRequest(): void
    {
        $client = new FakeApiClient();

        $result = $this->workspace->run(['command' => 'validate', '--check' => 'hardcoded-string'], $client);

        self::assertSame(ExitCode::Usage->value, $result['code']);
        self::assertStringContainsString('hardcoded-strings', $result['stderr'], 'komunikat mówi, co wolno wpisać');
        self::assertSame(0, (new RecordedRequests($client))->count(), 'literówka nie kosztuje żądania');
    }
}
