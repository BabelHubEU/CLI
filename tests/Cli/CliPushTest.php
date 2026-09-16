<?php

declare(strict_types=1);

namespace Tests\Cli;

use BabelHub\Cli\Config\ProjectConfig;
use BabelHub\Cli\Config\ProjectConfigStore;
use BabelHub\Cli\Console\Application;
use BabelHub\Cli\ExitCode;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Cli\Support\FakeApiClient;
use Tests\Cli\Support\GitWorkspace;

/**
 * `babelhub push` — kod wyjścia, partia i rozdział strumieni.
 *
 * Kody wyjścia są tu sprawdzane **każdy z osobna**, bo to one są kontraktem produktu (1.16):
 * `6` przy niepełnym pushu znaczy „wróć do brakujących pozycji”, `4` znaczy „poczekaj i powtórz”,
 * a `3` znaczy „napraw poświadczenie”. Zlepienie ich w jedno „nie udało się” odbiera skryptowi
 * CI jedyną informację, po którą sięgnął po to narzędzie.
 *
 * Partia to **suma** kluczy ze skanu i z pliku źródłowego — i to jest tu testowane na payloadzie,
 * który faktycznie dostała atrapa klienta, a nie na komunikacie dla człowieka: klucz używany
 * dynamicznie (`t('a.' ~ $x)`) nie ma literału w kodzie, więc wysłanie samego skanu zgasiłoby
 * czyjąś pracę przy domyślnym `mark_missing_as_removed`.
 */
final class CliPushTest extends TestCase
{
    private const API_URL = 'https://babelhub.example';
    private const SYNC_PATH = '/api/v1/projects/learnhub/sync';

    private string $root;

    private ?GitWorkspace $git = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/babelhub-cli-test-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);

        parent::tearDown();
    }

    public function testADryRunSendsNothingAndStillReportsTheCounts(): void
    {
        $this->writeFullProject();
        $client = $this->client();

        $result = $this->runPush(['--dry-run' => true], $client);

        self::assertSame(ExitCode::Success->value, $result['code']);
        self::assertSame([], $client->requests, '--dry-run istnieje po to, żeby nie zmienić niczego w projekcie');
        self::assertStringContainsString('[dry-run] Wysłałbym 3 kluczy do projektu learnhub', $result['stdout']);
        self::assertStringContainsString('z tekstem źródłowym: 2', $result['stdout']);
        self::assertStringContainsString(
            'Skan: 1 plików, 2 kluczy; teksty źródłowe: 2 (en_GB).',
            $result['stderr'],
            'postęp idzie na stderr — stdout jest zarezerwowany na wynik',
        );
    }

    public function testASuccessfulPushReturnsZeroAndReportsTheCounts(): void
    {
        $this->writeFullProject();
        $client = $this->client()->script('POST', self::SYNC_PATH, [
            'application' => 'learnhub',
            'created' => 2,
            'updated' => 1,
            'removed' => 0,
            'unchanged' => 3,
            'source_changed' => 1,
            'skipped' => [],
        ]);

        $result = $this->runPush([], $client);

        self::assertSame(ExitCode::Success->value, $result['code']);
        self::assertStringContainsString(
            'learnhub: nowe 2, zmienione 1, usunięte 0, bez zmian 3 (teksty źródłowe zmienione: 1)',
            $result['stdout'],
        );

        self::assertSame(1, $client->callCount('POST', self::SYNC_PATH), 'cały stan kodu jedzie jednym żądaniem');
        self::assertSame('POST', $client->requests[0]['method'] ?? null);
        self::assertSame(self::SYNC_PATH, $client->requests[0]['path'] ?? null);

        $payload = $client->requests[0]['payload'] ?? [];
        self::assertSame('en_GB', $payload['source_locale'] ?? null);
        self::assertTrue($payload['mark_missing_as_removed'] ?? null, 'domyślnie nieobecne klucze są oznaczane jako usunięte');
        self::assertSame([
            ['key' => 'a.one', 'source' => 'One'],
            ['key' => 'b.two', 'source' => 'Two'],
            ['key' => 'c.three', 'source' => null],
        ], $payload['keys'] ?? null, 'partia to suma skanu i pliku źródłowego, posortowana po kluczu');
    }

    public function testKeepMissingSwitchesOffTheRemovalFlag(): void
    {
        $this->writeFullProject();
        $client = $this->client()->script('POST', self::SYNC_PATH, $this->syncResponse());

        $result = $this->runPush(['--keep-missing' => true], $client);

        self::assertSame(ExitCode::Success->value, $result['code']);
        self::assertFalse(
            ($client->requests[0]['payload'] ?? [])['mark_missing_as_removed'] ?? null,
            'push jednego modułu nie może oznaczać kluczy spoza partii jako usunięte',
        );
    }

    /**
     * `.babelhub.yml` może wyłączyć oznaczanie nieobecnych kluczy **na stałe** — po to, żeby
     * repozytorium, które wysyła klucze częściami (albo z kilku modułów), nie musiało pamiętać
     * o `--keep-missing` przy każdym uruchomieniu i w każdym fragmencie CI.
     */
    public function testTheConfigurationCanSwitchOffTheRemovalFlag(): void
    {
        $this->writeFullProject();
        $this->useYamlConfig(markMissingAsRemoved: false);

        $client = $this->client()->script('POST', self::SYNC_PATH, $this->syncResponse());
        $result = $this->runPush([], $client);

        self::assertSame(ExitCode::Success->value, $result['code']);
        self::assertFalse(
            ($client->requests[0]['payload'] ?? [])['mark_missing_as_removed'] ?? null,
            'konfiguracja w repozytorium ma obowiązywać bez flagi',
        );
    }

    /** Flaga wygrywa z plikiem: plik mówi, jak zespół pracuje, flaga jest decyzją na jedno uruchomienie. */
    public function testTheFlagWinsOverTheConfiguration(): void
    {
        $this->writeFullProject();
        $this->useYamlConfig(markMissingAsRemoved: true);

        $client = $this->client()->script('POST', self::SYNC_PATH, $this->syncResponse());
        $this->runPush(['--keep-missing' => true], $client);

        self::assertFalse(
            ($client->requests[0]['payload'] ?? [])['mark_missing_as_removed'] ?? null,
            '`--keep-missing` wygrywa z `upload.mark_missing_as_removed: true`',
        );
    }

    /**
     * Podmienia `.babelhub.json` na `.babelhub.yml` — oba naraz są błędem (dwa źródła prawdy),
     * a to jest właśnie ten scenariusz, w którym repozytorium przechodzi na nowy format.
     */
    private function useYamlConfig(bool $markMissingAsRemoved, bool $unapproveChanged = false): void
    {
        @unlink($this->root . '/' . ProjectConfig::FILE_NAME);
        $this->write(ProjectConfig::FILE_NAME_YAML, implode("\n", [
            'project: learnhub',
            'source_locale: en_GB',
            'files:',
            '  pattern: "translations/messages.{lang}.php"',
            'scan:',
            '  paths: [src]',
            'upload:',
            '  mark_missing_as_removed: ' . ($markMissingAsRemoved ? 'true' : 'false'),
            '  unapprove_changed: ' . ($unapproveChanged ? 'true' : 'false'),
        ]) . "\n");
    }

    // -----------------------------------------------------------------
    // `unapprove_changed`: co się dzieje z zatwierdzeniem po zmianie źródła (1.19)
    // -----------------------------------------------------------------

    /**
     * **Domyślnie zatwierdzenia zostają.** `push` chodzi w CI po każdym commicie, a większość
     * zmian tekstu źródłowego to literówki — cofanie zatwierdzeń „na wszelki wypadek” zamieniłoby
     * każdy przebieg w kolejkę pracy dla recenzentów.
     */
    public function testApprovalsSurviveASourceChangeByDefault(): void
    {
        $this->writeFullProject();
        $client = $this->client()->script('POST', self::SYNC_PATH, $this->syncResponse());

        $this->runPush([], $client);

        self::assertFalse(
            ($client->requests[0]['payload'] ?? [])['unapprove_changed'] ?? null,
            'pole jedzie w każdym żądaniu (kontrakt), ale domyślnie mówi „nie”',
        );
    }

    public function testTheFlagAsksTheServerToUnapproveChangedTranslations(): void
    {
        $this->writeFullProject();
        $client = $this->client()->script('POST', self::SYNC_PATH, $this->syncResponse());

        $this->runPush(['--unapprove-changed' => true], $client);

        self::assertTrue(
            ($client->requests[0]['payload'] ?? [])['unapprove_changed'] ?? null,
            'to jest jedyny sposób, w jaki zespół mówi „zmiana źródła unieważnia zatwierdzenie”',
        );
    }

    public function testTheConfigurationCanAskForUnapprovingOnEveryPush(): void
    {
        $this->writeFullProject();
        $this->useYamlConfig(markMissingAsRemoved: true, unapproveChanged: true);

        $client = $this->client()->script('POST', self::SYNC_PATH, $this->syncResponse());
        $this->runPush([], $client);

        self::assertTrue(
            ($client->requests[0]['payload'] ?? [])['unapprove_changed'] ?? null,
            '`upload.unapprove_changed: true` to decyzja zespołu — ma obowiązywać bez flagi',
        );
    }


    public function testANonEmptySkippedMapMeansThePushIsNotComplete(): void
    {
        $this->writeFullProject();
        $client = $this->client()->script('POST', self::SYNC_PATH, $this->syncResponse([
            'skipped' => ['Zły Klucz' => 'Nieprawidłowy format klucza.'],
        ]));

        $result = $this->runPush([], $client);

        self::assertSame(
            ExitCode::CheckFailed->value,
            $result['code'],
            'część partii nie dotarła — zero znaczyłoby „nie wiem, czy wszystko doszło”',
        );
        self::assertStringContainsString('Pominięty Zły Klucz: Nieprawidłowy format klucza.', $result['stderr']);
        self::assertStringContainsString('Serwis pominął 1 pozycji', $result['stderr']);
    }

    public function testATooManyRequestsResponseGivesTheRateLimitedCode(): void
    {
        $this->writeFullProject();
        $client = $this->client()->script(
            'POST',
            self::SYNC_PATH,
            ['error' => 'rate_limited', 'message' => 'Za dużo żądań.'],
            429,
        );

        $result = $this->runPush([], $client);

        self::assertSame(ExitCode::RateLimited->value, $result['code'], 'jedyny błąd, który mija sam, ma własny kod');
        self::assertStringContainsString('Za dużo żądań.', $result['stderr']);
        self::assertSame('', $result['stdout'], 'nieudany push nie ma wyniku — na stdout nie może być niczego do sparsowania');
    }

    public function testAnUnauthorizedResponseGivesTheUnauthorizedCode(): void
    {
        $this->writeFullProject();
        $client = $this->client()->script(
            'POST',
            self::SYNC_PATH,
            ['error' => 'unauthorized', 'message' => 'Token wygasł.'],
            401,
        );

        $result = $this->runPush([], $client);

        self::assertSame(ExitCode::Unauthorized->value, $result['code'], '401/403 znaczy „napraw poświadczenie”, a nie „coś się nie udało”');
        self::assertStringContainsString('Token wygasł.', $result['stderr']);
    }

    public function testAMissingSourceFileWarnsButStillPushesTheKeys(): void
    {
        $this->writeConfig();
        $this->writeKeys();
        $client = $this->client()->script('POST', self::SYNC_PATH, $this->syncResponse());

        $result = $this->runPush([], $client);

        self::assertSame(ExitCode::Success->value, $result['code'], 'brak pliku źródłowego to nie błąd — projekt może dopiero zaczynać');
        self::assertStringContainsString('Nie ma pliku źródłowego', $result['stderr']);
        self::assertStringNotContainsString(
            'Nie ma pliku źródłowego',
            $result['stdout'],
            'ostrzeżenie na stdout psułoby każdy skrypt parsujący wynik',
        );
        self::assertSame([
            ['key' => 'a.one', 'source' => null],
            ['key' => 'c.three', 'source' => null],
        ], ($client->requests[0]['payload'] ?? [])['keys'] ?? null);
    }

    public function testAKeyThatOnlyLivesInTheSourceFileIsStillPushed(): void
    {
        // Klucz używany dynamicznie nie ma literału w kodzie — deklaracją jest plik źródłowy.
        $this->writeConfig();
        $this->write('src/Keys.php', "<?php\n\n// brak literałów: klucz powstaje dynamicznie\n");
        $this->writeSourceMessages(['dynamic.key' => 'Składany w kodzie']);
        $client = $this->client()->script('POST', self::SYNC_PATH, $this->syncResponse());

        $result = $this->runPush([], $client);

        self::assertSame(ExitCode::Success->value, $result['code']);
        self::assertSame(
            [['key' => 'dynamic.key', 'source' => 'Składany w kodzie']],
            ($client->requests[0]['payload'] ?? [])['keys'] ?? null,
        );
    }

    public function testWithNothingToSendTheCommandIsAUsageError(): void
    {
        $this->writeConfig();
        $this->write('src/Keys.php', "<?php\n\n// pusty projekt: ani jednego klucza\n");
        $client = $this->client();

        $result = $this->runPush([], $client);

        self::assertSame(ExitCode::Usage->value, $result['code']);
        self::assertSame([], $client->requests, 'pusta partia nie ma po co iść do serwisu');
        self::assertStringContainsString('scan.paths', $result['stderr'], 'komunikat ma wskazać, gdzie szukać przyczyny');
    }

    public function testInJsonModeTheServerBodyIsPrintedVerbatim(): void
    {
        $this->writeFullProject();
        $body = '{"application":"learnhub","created":2,"updated":0,"removed":0,"unchanged":0,"source_changed":0,"skipped":{}}';
        $client = $this->client()->scriptRaw('POST', self::SYNC_PATH, 200, $body);

        $result = $this->runPush(['--output' => 'json'], $client);

        self::assertSame(ExitCode::Success->value, $result['code']);
        self::assertSame(
            $body,
            trim($result['stdout']),
            'pusta mapa `skipped` po drodze przez json_decode+json_encode stałaby się `[]` — innym typem, niż obiecuje API',
        );
        self::assertSame(1, substr_count($result['stdout'], "\n"), 'w trybie json na stdout jest dokładnie jeden obiekt');
    }

    private function client(): FakeApiClient
    {
        return new FakeApiClient(self::API_URL, 'pat_test.sekret');
    }

    // -----------------------------------------------------------------
    // Znacznik synchronizacji w git-cie (1.18)
    // -----------------------------------------------------------------

    /**
     * Mechanizm istnieje po to, żeby CI nie wysyłało tego samego przy każdym przebiegu —
     * więc test sprawdza **brak żądania**, a nie komunikat: brak żądania jest jedynym dowodem,
     * że `sync` naprawdę nic nie kosztował.
     */
    public function testASecondPushOfTheSameCommitSendsNothing(): void
    {
        $this->writeFullProject();
        $this->gitInit();
        $client = $this->client()->script('POST', self::SYNC_PATH, $this->syncResponse());

        $first = $this->runPush([], $client);
        self::assertSame(ExitCode::Success->value, $first['code']);
        self::assertCount(1, $client->requests);

        $second = $this->runPush(['--output' => 'json'], $client);

        self::assertSame(ExitCode::Success->value, $second['code']);
        self::assertCount(1, $client->requests, 'drugi push nie może wysłać niczego do serwisu');
        $document = json_decode(trim($second['stdout']), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(true, $document['skipped']);
        self::assertSame('git', $document['reason']);
        self::assertSame('babelhub/sync/main', $document['marker']);
        self::assertSame($this->gitHead(), $document['commit']);
        self::assertSame(1, substr_count($second['stdout'], "\n"), 'w trybie json na stdout jest dokładnie jeden obiekt');
        self::assertSame(1, $this->gitTags(), 'po pushu jest dokładnie jeden znacznik');
    }

    public function testForceSendsTheSameCommitAgain(): void
    {
        $this->writeFullProject();
        $this->gitInit();
        $client = $this->client()->script('POST', self::SYNC_PATH, $this->syncResponse());

        $this->runPush([], $client);
        $forced = $this->runPush(['--force' => true], $client);

        self::assertSame(ExitCode::Success->value, $forced['code']);
        self::assertCount(2, $client->requests, '--force jest jedynym sposobem, żeby wysłać ten sam commit ponownie');
    }

    /**
     * Zmiana w kodzie i w pliku źródłowym to nowy stan do wysłania; zmiana w pliku języka
     * **docelowego** (to, co zostawia `pull`) — nie. Bez tego rozróżnienia mechanizm nie
     * zadziałałby nigdy po pierwszym `sync`.
     */
    public function testOnlyUncommittedChangesInThePushedFilesCancelTheSkip(): void
    {
        $this->writeFullProject();
        $this->gitInit();
        $client = $this->client()->script('POST', self::SYNC_PATH, $this->syncResponse());
        $this->runPush([], $client);

        $this->write('translations/messages.pl_PL.php', "<?php\n\nreturn ['a.one' => 'Jeden'];\n");
        $this->runPush([], $client);
        self::assertCount(1, $client->requests, 'plik docelowy zmieniony przez pull nie jest częścią pushu');

        $this->write('src/Keys.php', "<?php\n\ntrans('a.one');\ntrans('d.four');\n");
        $this->runPush([], $client);
        self::assertCount(2, $client->requests, 'zmiana w kodzie to nowy stan do wysłania');
    }

    /** Nieudany push (kod `6`) nie może zamrozić stanu: następny przebieg wysyła wszystko. */
    public function testAFailedPushDoesNotMoveTheMarker(): void
    {
        $this->writeFullProject();
        $this->gitInit();
        $client = $this->client()
            ->script('POST', self::SYNC_PATH, $this->syncResponse(['skipped' => ['zly.klucz' => 'Zły format.']]))
            ->script('POST', self::SYNC_PATH, $this->syncResponse());

        $failed = $this->runPush([], $client);
        self::assertSame(ExitCode::CheckFailed->value, $failed['code']);
        self::assertSame(0, $this->gitTags(), 'niekompletny push nie może zostawić znacznika');

        $retry = $this->runPush([], $client);
        self::assertSame(ExitCode::Success->value, $retry['code']);
        self::assertCount(2, $client->requests, 'po nieudanym pushu następny przebieg musi wysłać wszystko');
        self::assertSame(1, $this->gitTags());
    }

    public function testNoGitTagDisablesTheWholeMechanism(): void
    {
        $this->writeFullProject();
        $this->gitInit();
        $client = $this->client()->script('POST', self::SYNC_PATH, $this->syncResponse());

        $this->runPush(['--no-git-tag' => true], $client);
        $second = $this->runPush(['--no-git-tag' => true], $client);

        self::assertSame(ExitCode::Success->value, $second['code']);
        self::assertSame(0, $this->gitTags(), '--no-git-tag nie może zostawiać tagów w repozytorium');
        self::assertCount(2, $client->requests, 'bez znacznika każde uruchomienie wysyła stan kodu');
    }

    /** Katalog bez gita działa jak dotąd: push idzie, nic nie pęka, żaden tag nie powstaje. */
    public function testAProjectOutsideGitPushesNormally(): void
    {
        $this->writeFullProject();
        $client = $this->client()->script('POST', self::SYNC_PATH, $this->syncResponse());

        $result = $this->runPush([], $client);

        self::assertSame(ExitCode::Success->value, $result['code']);
        self::assertCount(1, $client->requests);
        self::assertStringNotContainsString('Znacznik', $result['stderr'], 'bez repozytorium nie ma o czym mówić');
    }

    /**
     * `--no-git-tag` i `--push-tag` to sprzeczność, a nie „jedna wygrywa”: pierwszy wyłącza
     * znacznik, drugi go wysyła. Milczące zignorowanie jednej z flag to cicha decyzja —
     * a błąd użycia ma w tym CLI własny kod (`2`), więc nie musi udawać niczego innego.
     */
    public function testContradictoryGitFlagsAreAUsageError(): void
    {
        $this->writeFullProject();
        $this->gitInit();
        $client = $this->client()->script('POST', self::SYNC_PATH, $this->syncResponse());

        $result = $this->runPush(['--no-git-tag' => true, '--push-tag' => true], $client);

        self::assertSame(ExitCode::Usage->value, $result['code']);
        self::assertStringContainsString('wykluczają się', $result['stderr']);
        self::assertSame([], $client->requests, 'błąd użycia nie może wysłać niczego do serwisu');
        self::assertSame(0, $this->gitTags());
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function syncResponse(array $overrides = []): array
    {
        return $overrides + [
            'application' => 'learnhub',
            'created' => 0,
            'updated' => 0,
            'removed' => 0,
            'unchanged' => 3,
            'source_changed' => 0,
            'skipped' => [],
        ];
    }

    /**
     * Pełny projekt: konfiguracja, dwa klucze w kodzie i dwa teksty w pliku źródłowym
     * (`b.two` występuje **tylko** w pliku, więc partia musi być sumą).
     */
    private function writeFullProject(): void
    {
        $this->writeConfig();
        $this->writeKeys();
        $this->writeSourceMessages(['a.one' => 'One', 'b.two' => 'Two']);
    }

    private function writeConfig(): void
    {
        (new ProjectConfigStore($this->root))->save(new ProjectConfig(
            project: 'learnhub',
            sourceLocale: 'en_GB',
            filePattern: 'translations/messages.{lang}.php',
            fileFormat: ProjectConfig::FORMAT_PHP,
            scanPaths: ['src'],
        ), $this->root);
    }

    private function writeKeys(): void
    {
        $this->write('src/Keys.php', implode("\n", [
            '<?php',
            '',
            "trans('a.one');",
            "trans('c.three');",
        ]) . "\n");
    }

    /** @param array<string, string> $messages */
    private function writeSourceMessages(array $messages): void
    {
        $lines = ['<?php', '', 'return ['];

        foreach ($messages as $key => $value) {
            $lines[] = sprintf('    %s => %s,', var_export($key, true), var_export($value, true));
        }

        $lines[] = '];';
        $lines[] = '';

        $this->write('translations/messages.en_GB.php', implode("\n", $lines));
    }

    /**
     * @param array<string, mixed> $arguments dodatkowe argumenty polecenia, jak z linii poleceń
     *
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function runPush(array $arguments, FakeApiClient $client): array
    {
        $stdout = new BufferedOutput();
        $stderr = new BufferedOutput();

        $code = Application::run(
            credentialsPath: $this->root . '/credentials.json',
            stdout: $stdout,
            stderr: $stderr,
            input: new ArrayInput([
                'command' => 'push',
                '--directory' => $this->root,
                '--api-url' => self::API_URL,
                '--token' => 'pat_test.sekret',
            ] + $arguments),
            clientFactory: static fn (string $url, ?string $token): FakeApiClient => $client,
        );

        return ['code' => $code, 'stdout' => $stdout->fetch(), 'stderr' => $stderr->fetch()];
    }

    private function write(string $relativePath, string $contents): void
    {
        $path = $this->root . '/' . $relativePath;
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($path, $contents);
    }

    /** Repozytorium git w katalogu projektu z **jednym commitem** całego stanu. */
    private function gitInit(): void
    {
        if (!GitWorkspace::available()) {
            self::markTestSkipped('git nie jest dostępny w tym środowisku');
        }

        $this->git = new GitWorkspace($this->root);
        $this->git->init();
        $this->git->commit('stan początkowy');
    }

    private function gitHead(): string
    {
        return $this->gitWorkspace()->revParse('HEAD') ?? self::fail('brak commita');
    }

    private function gitTags(): int
    {
        return count($this->gitWorkspace()->tags());
    }

    private function gitWorkspace(): GitWorkspace
    {
        return $this->git ??= new GitWorkspace($this->root);
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
