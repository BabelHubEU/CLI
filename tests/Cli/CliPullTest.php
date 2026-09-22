<?php

declare(strict_types=1);

namespace Tests\Cli;

use BabelHub\Cli\Application\CliPull;
use BabelHub\Cli\Config\CatalogLock;
use BabelHub\Cli\Config\ProjectConfig;
use BabelHub\Cli\ExitCode;
use BabelHub\Cli\Files\LocaleFile;
use BabelHub\Cli\Files\LocaleWriteOptions;
use BabelHub\Cli\Output\OutputMode;
use BabelHub\Cli\Output\Printer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Cli\Support\CliWorkspace;
use Tests\Cli\Support\FakeApiClient;
use Tests\Cli\Support\RecordedRequests;

/**
 * `babelhub pull` — trzy obietnice, które ten kod składa i które tu sprawdzamy.
 *
 * 1. **Gdy katalog się nie zmienił, plik zostaje nietknięty.** Zapytanie idzie z `If-None-Match`
 *    wersją z `.babelhub.lock.json`, a `304` znaczy „nic nie rób”. Dowodem jest tu **treść
 *    i `mtime`** pliku podmienionego na lokalny ślad: test, który sprawdza tylko treść, przechodzi
 *    również wtedy, gdy kod przepisał plik tą samą zawartością (a to nadal diff w repozytorium).
 * 2. **Pusty katalog nie kasuje pracy.** Zapis pustej tablicy skasowałby teksty, których jeszcze
 *    nikt nie zatwierdził, więc `translations: {}` to „nie ma czego pobierać”, a nie „pusty plik”.
 * 3. **`--check` nic nie zapisuje i kończy się kodem `6` przy rozjeździe.** To tryb dla CI:
 *    „czy ktoś zapomniał zrobić pull”, bez ryzyka, że pipeline sam dopisze coś do repozytorium.
 *
 * Kody wyjścia sprawdzamy każdy z osobna (`2` brak języków, `3` poświadczenie, `4` limit,
 * `6` rozjazd), bo od kodu zależy reakcja pipeline'u — zlepienie ich w jedno „nie udało się”
 * odbiera skryptowi jedyną informację, po którą sięgnął po to narzędzie.
 */
final class CliPullTest extends TestCase
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

    /** @return iterable<string, array{format: string, pattern: string, file: string}> */
    public static function formats(): iterable
    {
        yield 'php' => [
            'format' => ProjectConfig::FORMAT_PHP,
            'pattern' => 'translations/messages.{lang}.php',
            'file' => 'translations/messages.pl_PL.php',
        ];
        yield 'json' => [
            'format' => ProjectConfig::FORMAT_JSON,
            'pattern' => 'translations/messages.{lang}.json',
            'file' => 'translations/messages.pl_PL.json',
        ];
    }

    #[DataProvider('formats')]
    public function testACatalogIsWrittenInTheProjectsFormatAndRecordedInTheLock(string $format, string $pattern, string $file): void
    {
        $messages = ['a.one' => 'One', 'b.two' => 'Two'];

        $client = $this->scriptProject($this->client());
        $this->scriptCatalog($client, 7, $messages);

        $result = $this->runPull($client, $this->config($format, $pattern));

        self::assertSame(ExitCode::Success->value, $result['code']);
        self::assertStringContainsString('zapisane (2 kluczy, wersja 7)', $result['stdout']);

        self::assertSame(
            $messages,
            (new LocaleFile($format))->read($this->workspace->path($file)),
            'format bierze się z .babelhub.json — projekt czytający PHP nie może dostać JSON-a',
        );

        $raw = $this->workspace->read($file);
        if ($format === ProjectConfig::FORMAT_JSON) {
            self::assertSame($messages, $this->decode($raw), 'JSON zostaje JSON-em: mapa klucz → tekst, bez naszych metadanych');
        } else {
            self::assertStringContainsString("'a.one' => 'One',", $raw, 'PHP zostaje PHP-em — inaczej cały diff projektu zamienia się w jeden commit');
        }

        $lock = $this->workspace->loadLock();
        self::assertNotNull($lock);
        self::assertSame('learnhub', $lock->project);
        self::assertSame(7, $lock->versionFor('pl_PL'), 'bez wersji w locku następny pull nie ma czym zadać If-None-Match');
        self::assertSame(2, $lock->keysFor('pl_PL'), 'liczba kluczy opisuje to, co leży w repozytorium');
        self::assertNotSame('', $lock->locales['pl_PL']['pulled_at'] ?? '', 'data pobrania mówi, z kiedy jest ten stan');
    }

    public function testTheSecondRunAsksWithTheVersionFromTheLock(): void
    {
        $client = $this->scriptProject($this->client());
        $this->scriptCatalog($client, 7, ['a.one' => 'One']);
        $this->scriptCatalog($client, 7, ['a.one' => 'One']);

        $this->runPull($client);
        $this->runPull($client);

        $requests = new RecordedRequests($client);

        self::assertSame(
            [],
            $requests->headers('GET', self::CATALOG_PATH, 0),
            'pierwszy pull nie ma locka, więc nie ma czego rewalidować — pyta o pełny katalog',
        );
        self::assertSame(
            ['If-None-Match' => '"7"'],
            $requests->headers('GET', self::CATALOG_PATH, 1),
            'drugi pull pyta wersją z locka — to ona zamienia zapis pliku w 304 i zero diffu',
        );
    }

    public function testANotModifiedAnswerLeavesTheFileAndTheKeyCountAlone(): void
    {
        $file = 'translations/messages.pl_PL.php';

        $client = $this->scriptProject($this->client());
        $this->scriptCatalog($client, 7, ['a.one' => 'One', 'b.two' => 'Two']);
        $client->script('GET', self::CATALOG_PATH, [], 304);

        $this->runPull($client);
        self::assertTrue($this->workspace->exists($file));

        // Ślad lokalnej pracy: gdyby `304` cokolwiek zapisało, zmieniłaby się i treść, i mtime.
        $sentinel = "<?php\n\nreturn ['local' => 'edycja, która nie może zniknąć'];\n";
        $this->workspace->write($file, $sentinel);
        $this->workspace->setModificationTime($file, time() - 7200);
        $before = $this->workspace->modificationTime($file);

        $result = $this->runPull($client);

        self::assertSame(ExitCode::Success->value, $result['code']);
        self::assertStringContainsString('bez zmian (wersja 7)', $result['stdout']);

        self::assertSame(
            $sentinel,
            $this->workspace->read($file),
            '304 znaczy „nic nie rób” — nadpisanie pliku zabiłoby lokalną pracę i zrobiło diff z niczego',
        );
        self::assertSame(
            $before,
            $this->workspace->modificationTime($file),
            'mtime to drugi, niezależny dowód: plik nie został nawet dotknięty',
        );

        $lock = $this->workspace->loadLock();
        self::assertNotNull($lock);
        self::assertSame(
            2,
            $lock->keysFor('pl_PL'),
            'serwis nie przysłał treści, więc liczba kluczy idzie z poprzedniego locka — zero byłoby kłamstwem o katalogu',
        );
        self::assertSame(7, $lock->versionFor('pl_PL'));
    }

    /**
     * **Lock też nie może się zmienić, gdy serwis odpowiada `304`.**
     *
     * To jest regresja, która kosztowała pętlę w CI: gałąź `304` przepisywała `pulled_at`
     * bieżącym czasem, więc `babelhub sync` robił commit, commit wywoływał kolejny pipeline,
     * a ten następny commit — dziesięć commitów bota w pięć minut na LearnHubie (20:04–20:09),
     * każdy zmieniający wyłącznie tę datę.
     *
     * Data jest tu **podstawiona** (`SENTINEL`), a nie zmierzona po pierwszym `pull`: dwa
     * przebiegi w jednym teście dzieją się w tej samej sekundzie, więc `DATE_ATOM` dałby
     * identyczny napis i porównanie przeszłoby **także z błędem**. Sprawdzone: cofnięcie
     * naprawy nie wywracało wersji z porównaniem bajtów po dwóch `pull`-ach, a wywraca tę.
     */
    public function testANotModifiedAnswerDoesNotRewriteTheLock(): void
    {
        $this->workspace->writeProjectConfig();
        $this->workspace->writeCredentials();
        $this->workspace->write('translations/messages.pl_PL.php', "<?php\n\nreturn ['a.one' => 'One'];\n");
        $this->workspace->saveLock(new CatalogLock('learnhub', [
            'pl_PL' => ['version' => 7, 'keys' => 1, 'pulled_at' => 'SENTINEL'],
        ]));
        $lockBefore = $this->workspace->read(CatalogLock::FILE_NAME);

        $client = $this->scriptProject($this->client());
        $client->script('GET', self::CATALOG_PATH, [], 304);

        $result = $this->workspace->run(['command' => 'pull'], $client);

        self::assertSame(ExitCode::Success->value, $result['code']);
        self::assertSame(
            'SENTINEL',
            $this->workspace->loadLock()?->pulledAtFor('pl_PL'),
            'nic nie zapisano, więc data ostatniego zapisu pliku nie ma prawa się ruszyć',
        );
        self::assertSame(
            $lockBefore,
            $this->workspace->read(CatalogLock::FILE_NAME),
            'lock po 304 musi być identyczny — inaczej `git diff --quiet` w jobie widzi zmianę i commit leci w kółko',
        );
    }

    public function testAnEmptyCatalogNeitherCreatesNorTruncatesTheFile(): void
    {
        $file = 'translations/messages.pl_PL.php';
        $sentinel = "<?php\n\nreturn ['local' => 'tekst przygotowany lokalnie'];\n";
        $this->workspace->write($file, $sentinel);

        $client = $this->scriptProject($this->client());
        // `translations` jako mapa (`{}`), tak jak wysyła to serwis — pusta tablica PHP po drodze
        // przez json_encode stałaby się `[]` i test sprawdzałby inny kształt, niż obiecuje API.
        $client->scriptRaw('GET', self::CATALOG_PATH, 200, '{"application":"learnhub","locale":"pl_PL","version":3,"translations":{}}');

        $result = $this->runPull($client, null, false, OutputMode::Json);

        self::assertSame(ExitCode::Success->value, $result['code'], 'pusty katalog to nie błąd — to „nie ma czego pobierać”');
        self::assertSame(
            ['outcome' => 'empty', 'version' => 3, 'keys' => 0],
            $this->mapAt($this->decode(trim($result['stdout'])), 'locales')['pl_PL'] ?? null,
            '`empty` jest osobnym wynikiem także maszynowo — „pusto” i „nie ma czego pobierać” to dwie różne wiadomości',
        );
        self::assertSame(
            $sentinel,
            $this->workspace->read($file),
            'zapis pustej tablicy skasowałby pracę, której nikt jeszcze nie zatwierdził',
        );

        $lock = $this->workspace->loadLock();
        self::assertNotNull($lock);
        self::assertSame(3, $lock->versionFor('pl_PL'), 'wersja pustego katalogu też jest faktem — inaczej każdy pull pyta o pełny katalog');
        self::assertSame(0, $lock->keysFor('pl_PL'));

        // Drugi przebieg, tym razem bez pliku na dysku i w trybie dla człowieka: pusty katalog
        // nie może utworzyć pliku, a komunikat mówi wprost, że plik został nietknięty.
        $this->workspace->remove($file);
        $second = $this->runPull($client);

        self::assertSame(ExitCode::Success->value, $second['code']);
        self::assertStringContainsString('brak zatwierdzonych tekstów — plik nietknięty', $second['stdout']);
        self::assertFileDoesNotExist($this->workspace->path($file), '„brak tekstów” nie tworzy pliku językowego');
    }

    public function testInJsonModeTheOutcomeDictionaryIsPrintedOnStdout(): void
    {
        $client = $this->scriptProject($this->client());
        $this->scriptCatalog($client, 7, ['a.one' => 'One']);

        $result = $this->runPull($client, null, false, OutputMode::Json);

        self::assertSame(ExitCode::Success->value, $result['code']);

        $payload = $this->decode(trim($result['stdout']));
        self::assertSame('learnhub', $payload['project'] ?? null);
        self::assertFalse($payload['check_only'] ?? null);
        self::assertFalse($payload['drifted'] ?? null);
        self::assertSame($this->workspace->path(CatalogLock::FILE_NAME), $payload['lock_path'] ?? null);
        self::assertSame(
            ['outcome' => 'written', 'version' => 7, 'keys' => 1],
            $this->mapAt($payload, 'locales')['pl_PL'] ?? null,
            'słownik wyników (`PullOutcome`) jest kontraktem dla skryptów — nie tylko zdaniem dla człowieka',
        );
        self::assertSame(1, substr_count($result['stdout'], "\n"), 'w trybie json na stdout jest dokładnie jeden obiekt');
    }

    public function testTheCheckModeReportsDriftWithCodeSixAndWritesNothing(): void
    {
        $file = 'translations/messages.pl_PL.php';
        $this->workspace->saveLock(new CatalogLock('learnhub', [
            'pl_PL' => ['version' => 5, 'keys' => 2, 'pulled_at' => 'SENTINEL'],
        ]));

        $client = $this->scriptProject($this->client());
        $this->scriptCatalog($client, 9, ['a.one' => 'One', 'b.two' => 'Two', 'c.three' => 'Three']);

        $result = $this->runPull($client, null, true);

        self::assertSame(ExitCode::CheckFailed->value, $result['code'], 'rozjazd to wynik kontroli (6), a nie awaria narzędzia (1)');
        self::assertStringContainsString('rozjazd: serwis ma wersję 9 (3 kluczy)', $result['stdout']);
        self::assertStringContainsString('Repozytorium nie odpowiada serwisowi', $result['stderr']);

        self::assertFileDoesNotExist($this->workspace->path($file), '--check nie dopisuje plików do repozytorium — to cała jego wartość w CI');
        self::assertSame(
            ['If-None-Match' => '"5"'],
            (new RecordedRequests($client))->headers('GET', self::CATALOG_PATH),
            'rozjazd jest między lockiem a serwisem, więc pytamy wersją z locka',
        );

        $lock = $this->workspace->loadLock();
        self::assertNotNull($lock);
        self::assertSame('SENTINEL', $lock->locales['pl_PL']['pulled_at'] ?? null, '--check nie zapisuje locka: dowód rozjazdu ma zostać, jaki jest');
        self::assertSame(5, $lock->versionFor('pl_PL'));
    }

    public function testTheCheckModeReturnsZeroWhenTheRepositoryMatchesTheService(): void
    {
        $file = 'translations/messages.pl_PL.php';
        $this->workspace->write($file, "<?php\n\nreturn ['a.one' => 'One'];\n");
        $this->workspace->saveLock(new CatalogLock('learnhub', [
            'pl_PL' => ['version' => 7, 'keys' => 1, 'pulled_at' => 'SENTINEL'],
        ]));

        $client = $this->scriptProject($this->client());
        $client->script('GET', self::CATALOG_PATH, [], 304);

        $result = $this->runPull($client, null, true);

        self::assertSame(ExitCode::Success->value, $result['code'], 'zgodność z serwisem to zero — inaczej bramka CI nie ma stanu „przeszło”');
        self::assertStringContainsString('bez zmian (wersja 7)', $result['stdout']);
        self::assertStringContainsString('Sprawdzone — katalogi w repozytorium odpowiadają wersjom z serwisu.', $result['stderr']);
        self::assertSame("<?php\n\nreturn ['a.one' => 'One'];\n", $this->workspace->read($file), 'tryb kontroli nie przepisuje pliku');

        $lock = $this->workspace->loadLock();
        self::assertNotNull($lock);
        self::assertSame('SENTINEL', $lock->locales['pl_PL']['pulled_at'] ?? null, 'tryb --check nie zmienia repozytorium ani jego locka');
    }

    /**
     * `download.locales` z `.babelhub.yml` — repozytorium, które prowadzi jedną wersję językową,
     * nie ściąga reszty, a `pull --check` w CI pyta tylko o to, co ma w repo.
     *
     * Te trzy przypadki idą przez **komendę** (`Application::run`), a nie przez `CliPull`
     * bezpośrednio: sedno sprawy to odczyt konfiguracji i flaga, czyli warstwa wejścia.
     */
    public function testTheConfigurationCanRestrictWhichLocalesArePulled(): void
    {
        $this->workspace->writeProjectConfigYaml(['pl_PL']);
        $this->workspace->writeCredentials();

        $client = $this->client();
        $client->script('GET', self::PROJECT_PATH, [
            'project' => ['slug' => 'learnhub', 'name' => 'Demo', 'locales' => ['en_GB', 'pl_PL']],
        ]);
        $client->script('GET', self::CATALOG_PATH, [
            'application' => 'learnhub',
            'locale' => 'pl_PL',
            'version' => 7,
            'translations' => ['a.one' => 'One'],
        ]);

        $result = $this->workspace->run(['command' => 'pull'], $client);

        self::assertSame(ExitCode::Success->value, $result['code'], $result['stderr']);
        self::assertSame(
            0,
            (new RecordedRequests($client))->countPath(self::PROJECT_PATH . '/translations/en_GB'),
            'język spoza `download.locales` nie jest w ogóle pytany — inaczej limit z konfiguracji ogranicza tylko zapis',
        );
        self::assertFileExists($this->workspace->path('translations/messages.pl_PL.php'));
        self::assertFileDoesNotExist($this->workspace->path('translations/messages.en_GB.php'));
    }

    /** Flaga `--locale` wygrywa z konfiguracją w całości — mieszanie list daje zbiór nie do przewidzenia. */
    public function testTheLocaleFlagWinsOverTheConfiguration(): void
    {
        $this->workspace->writeProjectConfigYaml(['pl_PL']);
        $this->workspace->writeCredentials();

        $client = $this->client();
        $client->script('GET', self::PROJECT_PATH, [
            'project' => ['slug' => 'learnhub', 'name' => 'Demo', 'locales' => ['en_GB', 'pl_PL']],
        ]);
        $client->script('GET', self::PROJECT_PATH . '/translations/en_GB', [
            'application' => 'learnhub',
            'locale' => 'en_GB',
            'version' => 2,
            'translations' => ['a.one' => 'One'],
        ]);

        $result = $this->workspace->run(['command' => 'pull', '--locale' => ['en_GB']], $client);

        self::assertSame(ExitCode::Success->value, $result['code'], $result['stderr']);
        self::assertFileExists($this->workspace->path('translations/messages.en_GB.php'));
        self::assertFileDoesNotExist($this->workspace->path('translations/messages.pl_PL.php'));
    }

    /**
     * Język z konfiguracji, którego projekt **nie prowadzi**, to błąd użycia, a nie cicho
     * pominięta pozycja: `pull` nie zrobiłby nic i wyglądał na sukces — czyli konfiguracja
     * kłamałaby bez jednego komunikatu.
     */
    public function testAConfiguredLocaleOutsideTheProjectIsAUsageError(): void
    {
        $this->workspace->writeProjectConfigYaml(['de_DE']);
        $this->workspace->writeCredentials();

        $client = $this->client()->script('GET', self::PROJECT_PATH, [
            'project' => ['slug' => 'learnhub', 'name' => 'Demo', 'locales' => ['en_GB', 'pl_PL']],
        ]);

        $result = $this->workspace->run(['command' => 'pull'], $client);

        self::assertSame(ExitCode::Usage->value, $result['code']);
        self::assertStringContainsString('de_DE', $result['stderr']);
        self::assertStringContainsString('en_GB, pl_PL', $result['stderr'], 'komunikat mówi, co projekt faktycznie prowadzi');
    }

    public function testAProjectWithoutLocalesIsAUsageError(): void
    {
        $client = $this->client()->script('GET', self::PROJECT_PATH, [
            'project' => ['slug' => 'learnhub', 'name' => 'QA demo', 'locales' => []],
        ]);

        $result = $this->runPull($client);

        self::assertSame(ExitCode::Usage->value, $result['code'], 'projekt bez języków to konfiguracja do poprawienia, a nie błąd serwisu');
        self::assertStringContainsString('nie ma przypisanych języków', $result['stderr']);
        self::assertStringContainsString('Aplikacja → Języki projektu', $result['stderr'], 'komunikat mówi, gdzie to naprawić');
        self::assertSame(1, (new RecordedRequests($client))->count(), 'bez języków nie ma po co pytać o katalogi');
        self::assertFileDoesNotExist(
            $this->workspace->path(CatalogLock::FILE_NAME),
            'nieudany pull nie zostawia locka — lock opisuje stan repozytorium, który nie powstał',
        );
    }

    public function testAForbiddenProjectAnswerMapsToCodeThree(): void
    {
        $client = $this->client()->script('GET', self::PROJECT_PATH, [
            'error' => 'forbidden',
            'message' => 'Brak uprawnień translations:read.',
        ], 403);

        $result = $this->runPull($client);

        self::assertSame(ExitCode::Unauthorized->value, $result['code'], '403 znaczy „napraw poświadczenie”, a nie „spróbuj jeszcze raz”');
        self::assertStringContainsString('Brak uprawnień translations:read.', $result['stderr']);
        self::assertSame('', $result['stdout'], 'nieudany pull nie ma wyniku — na stdout nie ma czego parsować');
        self::assertFileDoesNotExist($this->workspace->path(CatalogLock::FILE_NAME));
    }

    public function testAForbiddenCatalogAnswerStopsTheRunAndWritesNothing(): void
    {
        $client = $this->scriptProject($this->client());
        $client->script('GET', self::CATALOG_PATH, [
            'error' => 'forbidden',
            'message' => 'Brak uprawnień translations:read.',
        ], 403);

        $result = $this->runPull($client);

        self::assertSame(ExitCode::Unauthorized->value, $result['code']);
        self::assertStringContainsString('pl_PL: Brak uprawnień translations:read.', $result['stderr'], 'komunikat mówi, którego języka dotyczy problem');
        self::assertFileDoesNotExist($this->workspace->path('translations/messages.pl_PL.php'));
        self::assertFileDoesNotExist($this->workspace->path(CatalogLock::FILE_NAME));
    }

    public function testATooManyRequestsAnswerMapsToCodeFour(): void
    {
        $client = $this->scriptProject($this->client());
        $client->script('GET', self::CATALOG_PATH, [
            'error' => 'rate_limited',
            'message' => 'Za dużo żądań.',
        ], 429);

        $result = $this->runPull($client);

        self::assertSame(
            ExitCode::RateLimited->value,
            $result['code'],
            'jedyny błąd, który mija sam, ma własny kod — pipeline ma poczekać, a nie poprawiać konfigurację',
        );
        self::assertStringContainsString('Za dużo żądań.', $result['stderr']);
        self::assertFileDoesNotExist($this->workspace->path(CatalogLock::FILE_NAME));
    }

    public function testTheCommandPassesCheckThroughToTheService(): void
    {
        $this->workspace->writeProjectConfig();
        $this->workspace->writeCredentials();
        $this->workspace->write('translations/messages.pl_PL.php', "<?php\n\nreturn ['a.one' => 'One'];\n");
        $this->workspace->saveLock(new CatalogLock('learnhub', [
            'pl_PL' => ['version' => 7, 'keys' => 1, 'pulled_at' => 'SENTINEL'],
        ]));

        $client = $this->scriptProject($this->client());
        $this->scriptCatalog($client, 8, ['a.one' => 'One']);

        $result = $this->workspace->run(['command' => 'pull', '--check' => true], $client);

        self::assertSame(
            ExitCode::CheckFailed->value,
            $result['code'],
            'kod wyjścia komendy musi dojść do pipeline\'u — inaczej bramka istnieje tylko w dokumentacji',
        );
        self::assertStringContainsString('rozjazd: serwis ma wersję 8', $result['stdout']);
        self::assertSame(['If-None-Match' => '"7"'], (new RecordedRequests($client))->headers('GET', self::CATALOG_PATH));
        self::assertSame('SENTINEL', $this->workspace->loadLock()?->locales['pl_PL']['pulled_at'] ?? null);
    }

    /**
     * Pusty katalog w serwisie **jest rozjazdem**, gdy lock mówi o innej wersji.
     *
     * Pierwsza wersja `pull` obsługiwała pusty katalog `continue`-em **przed** gałęzią `--check`,
     * więc bramka CI mówiła „zsynchronizowane” o repozytorium, które `pull` jeszcze by zmienił
     * (przepisałby lock na nową wersję, choćby po to, żeby zapamiętać, że katalog jest pusty).
     */
    public function testCheckModeReportsDriftWhenTheServerCatalogIsEmpty(): void
    {
        $this->workspace->saveLock(new CatalogLock(CliWorkspace::PROJECT, [
            'pl_PL' => ['version' => 5, 'keys' => 2, 'pulled_at' => '2026-01-01T00:00:00+00:00'],
        ]));
        $client = $this->scriptProject($this->client());
        $client->scriptRaw('GET', self::CATALOG_PATH, 200, '{"application":"learnhub","locale":"pl_PL","version":9,"translations":{}}');

        $result = $this->runPull($client, check: true);

        self::assertSame(ExitCode::CheckFailed->value, $result['code'], 'pusty katalog po nowej wersji to nadal rozjazd');
        self::assertStringContainsString('rozjazd', $result['stdout']);
        self::assertSame(
            '2026-01-01T00:00:00+00:00',
            $this->workspace->loadLock()?->locales['pl_PL']['pulled_at'] ?? null,
            'tryb sprawdzania nie może dotknąć locka',
        );
    }

    /**
     * Błąd w środku pętli: pliki przetworzone wcześniej **zostają**, lock **nie** powstaje,
     * a użytkownik dostaje o tym zdanie.
     *
     * Wcześniej `pull` kończył się w tym miejscu cichym `return` — plik `pl_PL` był już zapisany,
     * locka nie było, a na ekranie tylko błąd `de_DE`. Nikt nie wiedział, że cokolwiek się
     * zmieniło w repozytorium, a `pull --check` zgłaszał potem rozjazd bez wyjaśnienia.
     */
    public function testAFailingLocaleStopsTheLoopButStillReportsWhatWasWritten(): void
    {
        $this->workspace->write('translations/messages.pl_PL.php', "<?php\n\nreturn [\n    'stary.klucz' => 'stara wartość',\n];\n");
        $client = $this->scriptProject($this->client(), ['pl_PL', 'de_DE']);
        $this->scriptCatalog($client, 7, ['stary.klucz' => 'nowa wartość']);
        $client->scriptRaw('GET', '/api/v1/projects/learnhub/translations/de_DE', 403, '{"error":"forbidden","message":"Brak uprawnień."}');

        $result = $this->runPull($client);

        self::assertSame(ExitCode::Unauthorized->value, $result['code']);
        self::assertStringContainsString('nowa wartość', $this->workspace->read('translations/messages.pl_PL.php'));
        self::assertNull($this->workspace->loadLock(), 'lock, który opisuje połowę języków, kłamałby o repozytorium');
        self::assertStringContainsString('NIE został zaktualizowany', $result['stderr']);
        self::assertStringContainsString('pl_PL', $result['stdout'], 'raport musi pokazać, co zdążyło się zapisać');
    }

    /**
     * Awaria zapisu to kod `1` („padło lokalnie”), nie `2` („popraw wywołanie”).
     *
     * `docs/cli.md` rozdziela te dwa kody właśnie po to, żeby pipeline wiedział, czy ma poprawić
     * argumenty, czy zgłosić problem ze środowiskiem. Zbyt szerokie `catch (\RuntimeException)`
     * w komendzie zamieniało awarię dysku na `2`.
     */
    public function testAnIoFailureUsesTheLocalFailureCodeNotTheUsageCode(): void
    {
        $this->workspace->writeProjectConfig();
        $this->workspace->writeCredentials();
        // Ścieżka `translations` zajęta przez **plik**: katalogu nie da się utworzyć, a to jest
        // awaria lokalna, nie błąd użycia.
        $this->workspace->write('translations', 'nie katalog');
        $client = $this->scriptProject($this->client());
        $this->scriptCatalog($client, 3, ['auth.login.title' => 'Zaloguj']);

        $result = $this->workspace->run(['command' => 'pull'], $client);

        self::assertSame(ExitCode::Failure->value, $result['code'], 'awaria dysku to kod 1, nie 2');
        self::assertStringContainsString('Nie mogę utworzyć katalogu', $result['stderr']);
        self::assertStringNotContainsString('Warning', $result['stderr'], 'surowe ostrzeżenie PHP nie może wyprzedzać naszego zdania');
    }

    private function client(): FakeApiClient
    {
        return new FakeApiClient(CliWorkspace::API_URL, CliWorkspace::TOKEN);
    }

    private function config(
        string $format = ProjectConfig::FORMAT_PHP,
        string $pattern = 'translations/messages.{lang}.php',
        string $sort = LocaleWriteOptions::SORT_FILE,
        bool $nested = false,
        ?string $rootKey = null,
    ): ProjectConfig {
        return new ProjectConfig(
            project: CliWorkspace::PROJECT,
            sourceLocale: 'en_GB',
            filePattern: $pattern,
            fileFormat: $format,
            scanPaths: ['src'],
            downloadSort: $sort,
            downloadWriteNested: $nested,
            fileRootKey: $rootKey,
        );
    }

    /** @param list<string> $locales */
    private function scriptProject(FakeApiClient $client, array $locales = ['pl_PL']): FakeApiClient
    {
        return $client->script('GET', self::PROJECT_PATH, [
            'project' => [
                'slug' => CliWorkspace::PROJECT,
                'name' => 'QA demo',
                'source_locale' => 'en_GB',
                'locales' => $locales,
                'is_active' => true,
            ],
        ]);
    }

    /** @param array<string, string> $messages */
    private function scriptCatalog(FakeApiClient $client, int $version, array $messages): FakeApiClient
    {
        return $client->script('GET', self::CATALOG_PATH, [
            'application' => CliWorkspace::PROJECT,
            'locale' => 'pl_PL',
            'version' => $version,
            'translations' => $messages,
        ]);
    }

    // -----------------------------------------------------------------
    // 1.16b i 1.15: plik klienta jest klienta
    // -----------------------------------------------------------------

    /**
     * **`pull` nie przerabia pliku po swojemu.** Końce linii, wcięcia, klucze nieznane katalogowi
     * i kolejność zostają takie, jakie były — inaczej każdy przebieg daje diff w całym pliku,
     * a review przestaje cokolwiek znaczyć (dokładnie ten ból opisuje plan, 1.16b).
     */
    public function testPullKeepsTheStyleAndTheLocalKeysOfTheFileThatIsAlreadyThere(): void
    {
        $file = 'translations/messages.pl_PL.json';
        $this->workspace->write($file, "{\r\n  \"local.only\": \"Tylko lokalnie\",\r\n  \"z.last\": \"Stare\"\r\n}");

        $client = $this->scriptProject($this->client());
        $this->scriptCatalog($client, 4, ['a.new' => 'Nowe', 'z.last' => 'Nowe ostatnie']);

        $result = $this->runPull($client, $this->config(ProjectConfig::FORMAT_JSON, 'translations/messages.{lang}.json'));

        self::assertSame(ExitCode::Success->value, $result['code']);

        $raw = $this->workspace->read($file);

        self::assertStringContainsString("\r\n", $raw, 'CRLF zostaje CRLF — inaczej diff obejmuje każdą linię');
        self::assertStringNotContainsString("\n    \"local.only\"", str_replace("\r\n", '', $raw), 'wcięcie z pliku, nie nasze cztery spacje');
        self::assertStringContainsString('"local.only": "Tylko lokalnie"', $raw, 'klucz, którego katalog nie zna, zostaje w pliku');
        self::assertSame(
            ['local.only' => 'Tylko lokalnie', 'z.last' => 'Nowe ostatnie', 'a.new' => 'Nowe'],
            (new LocaleFile(ProjectConfig::FORMAT_JSON))->read($this->workspace->path($file)),
            'istniejące klucze na miejscach, nowy na końcu',
        );
    }

    public function testTheNamespacePatternWritesOneFilePerFirstKeySegment(): void
    {
        $client = $this->scriptProject($this->client());
        $this->scriptCatalog($client, 5, [
            'home.title' => 'Pulpit',
            'nav.title' => 'Menu',
            'nav.sub' => 'Podmenu',
        ]);

        $result = $this->runPull($client, $this->config(ProjectConfig::FORMAT_JSON, 'translations/{lang}/{ns}.json'));

        self::assertSame(ExitCode::Success->value, $result['code']);
        self::assertSame(
            ['home.title' => 'Pulpit'],
            (new LocaleFile(ProjectConfig::FORMAT_JSON))->read($this->workspace->path('translations/pl_PL/home.json')),
        );
        self::assertSame(
            ['nav.title' => 'Menu', 'nav.sub' => 'Podmenu'],
            (new LocaleFile(ProjectConfig::FORMAT_JSON))->read($this->workspace->path('translations/pl_PL/nav.json')),
        );
        self::assertSame(
            3,
            $this->workspace->loadLock()?->keysFor('pl_PL'),
            'lock liczy klucze w języku, nie pliki — inaczej rozjazd liczyłby się inaczej niż zapis',
        );
    }

    /**
     * **Koperta katalogu: `pull` aktualizuje mapę w środku, a nie dokłada drugiej kopii obok.**
     *
     * To regresja z pierwszego wdrożenia LearnHub: plik `bootstrap.{lang}.json` trzyma katalog
     * jako `{"locale": …, "translations": {…}}` (tak czyta go aplikacja klienta). Bez
     * `files.root_key` jedno `pull` zmieniło 89 kluczy w 181 liści — treść wróciła pod
     * `translations.*`, a `translations` przestało być płaską mapą tekstów, więc fallback
     * aplikacji był martwy, mimo że wszystkie bramki były zielone.
     */
    public function testPullUpdatesTheMapInsideTheEnvelopeInsteadOfAppendingASecondCopy(): void
    {
        $file = 'resources/translations/bootstrap.pl_PL.json';
        $this->workspace->write(
            $file,
            "{\n    \"locale\": \"pl_PL\",\n    \"translations\": {\n        \"local.only\": \"Tylko lokalnie\",\n        \"z.last\": \"Stare\"\n    }\n}\n",
        );

        $client = $this->scriptProject($this->client());
        $this->scriptCatalog($client, 7, ['a.new' => 'Nowe', 'z.last' => 'Nowe ostatnie']);

        $result = $this->runPull(
            $client,
            $this->config(
                ProjectConfig::FORMAT_JSON,
                'resources/translations/bootstrap.{lang}.json',
                rootKey: 'translations',
            ),
        );

        self::assertSame(ExitCode::Success->value, $result['code']);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($this->workspace->read($file), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(
            ['locale', 'translations'],
            array_keys($decoded),
            'dokument ma dalej dwa pola — bez drugiej kopii katalogu na wierzchu',
        );
        self::assertSame('pl_PL', $decoded['locale']);
        self::assertSame(
            ['local.only' => 'Tylko lokalnie', 'z.last' => 'Nowe ostatnie', 'a.new' => 'Nowe'],
            $decoded['translations'],
            'mapa w kopercie jest aktualizowana: klucz lokalny zostaje, istniejący się zmienia, nowy dochodzi',
        );
    }

    public function testEveryNamespaceFileKeepsItsOwnStyle(): void
    {
        $this->workspace->write('translations/pl_PL/nav.json', "{\n  \"nav.title\": \"Menu\"\n}\n");
        $this->workspace->write('translations/pl_PL/home.json', "{\n\t\"home.title\": \"Stary\"\n}\n");

        $client = $this->scriptProject($this->client());
        $this->scriptCatalog($client, 6, ['home.title' => 'Pulpit', 'nav.title' => 'Menu']);

        $this->runPull($client, $this->config(ProjectConfig::FORMAT_JSON, 'translations/{lang}/{ns}.json'));

        self::assertStringContainsString("\n  \"nav.title\"", $this->workspace->read('translations/pl_PL/nav.json'));
        self::assertStringContainsString(
            "\n\t\"home.title\"",
            $this->workspace->read('translations/pl_PL/home.json'),
            'każdy plik ma własny styl i pull nie uśrednia ich do jednego',
        );
    }

    public function testSortKeyOrdersTheWrittenFileAlphabetically(): void
    {
        $file = 'translations/messages.pl_PL.php';
        $this->workspace->write($file, "<?php\n\nreturn [\n    'z.last' => 'Stare',\n];\n");

        $client = $this->scriptProject($this->client());
        $this->scriptCatalog($client, 8, ['z.last' => 'Ostatni', 'a.first' => 'Pierwszy', 'm.middle' => 'Środkowy']);

        $this->runPull($client, $this->config(sort: LocaleWriteOptions::SORT_KEY));

        self::assertSame(
            ['a.first', 'm.middle', 'z.last'],
            array_keys((new LocaleFile(ProjectConfig::FORMAT_PHP))->read($this->workspace->path($file))),
            '`download.sort: key` to jawna decyzja zespołu — porządkuje także klucze już obecne w pliku',
        );
    }

    public function testWriteNestedWritesTheStructureThatI18nextReads(): void
    {
        $client = $this->scriptProject($this->client());
        $this->scriptCatalog($client, 9, ['nav.title' => 'Menu', 'nav.sub.deep' => 'Głęboko']);

        $this->runPull(
            $client,
            $this->config(ProjectConfig::FORMAT_JSON, 'translations/messages.{lang}.json', nested: true),
        );

        self::assertSame(
            "{\n    \"nav\": {\n        \"title\": \"Menu\",\n        \"sub\": {\n            \"deep\": \"Głęboko\"\n        }\n    }\n}\n",
            $this->workspace->read('translations/messages.pl_PL.json'),
            'projekt czytający strukturę dostaje strukturę — i to jest cały sens opcji',
        );
    }

    /**
     * Uruchomienie logiki `pull` bez komendy: `CliPull` dostaje drukarkę nad dwoma wyjściami
     * w pamięci, więc widać rozdział „wynik na stdout, komunikaty na stderr” bez sięgania do
     * `Application::run()`.
     *
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function runPull(
        FakeApiClient $client,
        ?ProjectConfig $config = null,
        bool $check = false,
        OutputMode $mode = OutputMode::Text,
    ): array {
        $stdout = new BufferedOutput();
        $stderr = new BufferedOutput();

        $code = (new CliPull($client, new Printer($stdout, $stderr, $mode)))
            ->run($config ?? $this->config(), $this->workspace->root(), $check);

        return ['code' => $code->value, 'stdout' => $stdout->fetch(), 'stderr' => $stderr->fetch()];
    }

    /** @return array<mixed> */
    private function decode(string $raw): array
    {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            self::fail(sprintf('Oczekiwano obiektu JSON, dostałem: %s', $raw));
        }

        return $decoded;
    }

    /**
     * @param array<mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function mapAt(array $payload, string $key): array
    {
        $value = $payload[$key] ?? null;
        if (!is_array($value)) {
            self::fail(sprintf('Pole "%s" nie jest mapą.', $key));
        }

        /** @var array<string, mixed> $value */
        return $value;
    }
}
