<?php

declare(strict_types=1);

namespace Tests\Cli;

use BabelHub\Cli\Checks\CheckName;
use BabelHub\Cli\Config\ProjectConfig;
use BabelHub\Cli\Config\ProjectConfigStore;
use BabelHub\Cli\Files\LocaleWriteOptions;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * `.babelhub.json` — kształt, **ścisła** walidacja i to, co dzieje się na dysku.
 *
 * Ten plik jest jedynym miejscem, w którym projekt mówi, **co** synchronizować, i leży
 * w repozytorium, więc walidacja jest tu produktem, a nie formalnością: cicho zignorowana
 * literówka (`sourcelocale`, `sourceLocale`) objawiłaby się dopiero tym, że `push` wysyła
 * teksty w złym języku. Dlatego każdy niepoprawny kształt ma swój test, a komunikat musi
 * **nazwać pole** — bez tego człowiek dostaje „błąd konfiguracji” i szuka go wzrokiem.
 *
 * Druga połowa pliku to magazyn: szukanie **w górę drzewa** (bo `push` uruchamia się równie
 * często z `src/`, jak z korzenia) i zapis atomowy (bo `init --force` nadpisuje plik, którym
 * posługuje się całe narzędzie).
 */
final class ProjectConfigTest extends TestCase
{
    private string $root;

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

    private function store(): ProjectConfigStore
    {
        return new ProjectConfigStore($this->root);
    }

    /** Konfiguracja w YAML — dokładnie tak, jak napisałby ją człowiek w repozytorium. */
    private function writeYaml(string $yaml): void
    {
        file_put_contents($this->root . '/' . ProjectConfig::FILE_NAME_YAML, $yaml . "\n");
    }

    public function testAValidFileIsParsedIntoEveryField(): void
    {
        $config = ProjectConfig::fromArray([
            '$schema' => 'https://babelhub.dev/schema/project-v1.json',
            'project' => 'learnhub',
            'source_locale' => 'en_GB',
            'files' => ['pattern' => 'translations/messages.{lang}.json', 'format' => 'json'],
            'scan' => ['paths' => ['src', 'templates']],
        ]);

        self::assertSame('learnhub', $config->project);
        self::assertSame('en_GB', $config->sourceLocale);
        self::assertSame('translations/messages.{lang}.json', $config->filePattern);
        self::assertSame(ProjectConfig::FORMAT_JSON, $config->fileFormat);
        self::assertSame(['src', 'templates'], $config->scanPaths);
    }

    public function testOptionalSectionsFallBackToTheDocumentedDefaults(): void
    {
        $config = ProjectConfig::fromArray([
            'project' => 'learnhub',
            'source_locale' => 'en_GB',
            'files' => ['pattern' => 'translations/messages.{lang}.php'],
        ]);

        self::assertSame(ProjectConfig::FORMAT_PHP, $config->fileFormat, 'PHP jest formatem domyślnym tego ekosystemu');
        self::assertSame(ProjectConfig::DEFAULT_SCAN_PATHS, $config->scanPaths);
        self::assertTrue($config->uploadMarkMissingAsRemoved, 'push domyślnie oznacza nieobecne klucze (D11)');
        self::assertSame([], $config->downloadLocales, 'pusty wybór znaczy „wszystkie języki projektu”');
        self::assertSame('error', $config->checksFailOn, 'domyślna bramka blokuje tylko błędy');
        self::assertSame(
            [CheckName::Quality],
            $config->checksRun,
            'domyślnie `validate` uruchamia kontrolę jakości — czyli dokładnie to, co robił przed 1.14',
        );
    }

    /**
     * `.babelhub.yml` — sekcje `upload` / `download` / `checks` (pozycja 1.12).
     *
     * Ten format istnieje, bo tak konfigurują się narzędzia tej kategorii i bo te trzy sekcje
     * czyta się w code review jak dokument: „co wysyłamy, co pobieramy, co blokuje merge”.
     */
    public function testAYamlFileCarriesTheUploadDownloadAndChecksSections(): void
    {
        $this->writeYaml(<<<'YAML'
        $schema: https://babelhub.dev/schema/project-v1.json
        project: learnhub
        source_locale: en_GB
        files:
          pattern: "translations/messages.{lang}.php"
          format: php
        scan:
          paths: [src, templates]
        upload:
          mark_missing_as_removed: false
        download:
          locales: [pl_PL]
        checks:
          fail_on: warning
        YAML);

        $config = $this->store()->load();

        self::assertNotNull($config);
        self::assertSame('learnhub', $config->project);
        self::assertSame(['src', 'templates'], $config->scanPaths);
        self::assertFalse($config->uploadMarkMissingAsRemoved, 'zespół może wyłączyć oznaczanie nieobecnych kluczy');
        self::assertSame(['pl_PL'], $config->downloadLocales, 'repozytorium z jedną wersją językową nie ściąga reszty');
        self::assertSame('warning', $config->checksFailOn, 'a bramka może być ostrzejsza niż domyślna');
    }

    public function testAYamlFileWithoutTheExtraSectionsUsesTheDefaults(): void
    {
        $this->writeYaml(<<<'YAML'
        project: learnhub
        source_locale: en_GB
        files:
          pattern: "translations/messages.{lang}.php"
          format: php
        YAML);

        $config = $this->store()->load();

        self::assertNotNull($config);
        self::assertTrue($config->uploadMarkMissingAsRemoved);
        self::assertSame([], $config->downloadLocales);
        self::assertSame(ProjectConfig::DEFAULT_FAIL_ON, $config->checksFailOn);
    }

    public function testAnUnknownKeyInANewSectionIsRejectedJustLikeAnywhereElse(): void
    {
        $this->writeYaml(<<<'YAML'
        project: learnhub
        source_locale: en_GB
        files:
          pattern: "translations/messages.{lang}.php"
        checks:
          fail_on: error
          hardcoded: true
        YAML);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/hardcoded/');

        $this->store()->load();
    }

    public function testAChecksThresholdOutsideTheClosedListIsRejected(): void
    {
        $this->writeYaml(<<<'YAML'
        project: learnhub
        source_locale: en_GB
        files:
          pattern: "translations/messages.{lang}.php"
        checks:
          fail_on: errorr
        YAML);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/fail_on/');

        $this->store()->load();
    }

    /**
     * `checks.run` — zestaw kontroli, które uruchamia `babelhub validate` bez `--check` (1.14).
     *
     * Repozytorium wie, czym jest jego bramka, a CI bywa cztery razy inne — więc deklaracja
     * „co sprawdza ten projekt” należy do pliku, a nie do czterech kroków w czterech pipeline'ach.
     */
    public function testTheChecksRunListDeclaresWhatTheGateRunsByDefault(): void
    {
        $this->writeYaml(<<<'YAML'
        project: learnhub
        source_locale: en_GB
        files:
          pattern: "translations/messages.{lang}.php"
        checks:
          run: [coverage, hardcoded-strings]
        YAML);

        $config = $this->store()->load();

        self::assertNotNull($config);
        self::assertSame(
            [CheckName::Coverage, CheckName::Hardcoded],
            $config->checksRun,
            'kolejność z pliku jest zachowana — w tej kolejności lecą komunikaty i wpisy w JSON-ie',
        );
    }

    /**
     * **Pusta lista jest błędem**, a nie „domyślnym zestawem”.
     *
     * `checks.run: []` to bramka, która zawsze przechodzi — gorsza niż jej brak, bo wygląda
     * na sprawdzenie. Kto chce domyślnego zestawu, usuwa pole.
     */
    /**
     * `files.pattern` z `{ns}` rozdziela katalog na pliki po przestrzeni nazw (1.15) — tak
     * trzymają tłumaczenia projekty z i18next. Wzorzec bez `{ns}` znaczy „wszystko w jednym
     * pliku”, czyli zachowanie dotychczasowe.
     */
    public function testThePatternCanAskForOneFilePerNamespace(): void
    {
        $config = ProjectConfig::fromArray([
            'project' => 'learnhub',
            'source_locale' => 'en_GB',
            'files' => ['pattern' => 'locales/{lang}/{ns}.json', 'format' => ProjectConfig::FORMAT_JSON],
        ]);

        self::assertTrue($config->usesNamespaces());
        self::assertSame(
            'locales/pl_PL/nav.json',
            $config->fileFor('pl_PL', null, 'nav'),
            'przestrzeń nazw podstawiamy tylko wtedy, gdy wzorzec o nią prosi',
        );
        self::assertSame(
            '/repo/locales/pl_PL/nav.json',
            $config->fileFor('pl_PL', '/repo', 'nav'),
            'korzeń doklejany raz, tak jak w pozostałych ścieżkach',
        );

        $single = ProjectConfig::fromArray([
            'project' => 'learnhub',
            'source_locale' => 'en_GB',
            'files' => ['pattern' => 'translations/messages.{lang}.php'],
        ]);

        self::assertFalse($single->usesNamespaces());
        self::assertSame('translations/messages.pl_PL.php', $single->fileFor('pl_PL', null, 'nav'), 'bez `{ns}` przestrzeń nazw nie zmienia ścieżki');
    }

    /**
     * Placeholdery są **zamkniętą listą**. Literówka (`{lange}`, `{lang_code}`) trafiłaby inaczej
     * do nazwy pliku dosłownie — a `pull` zapisałby wszystko do pliku z klamrami w nazwie
     * i nikt nie zauważyłby tego aż do commita.
     */
    public function testAnUnknownPlaceholderInThePatternIsRejectedWithTheList(): void
    {
        try {
            ProjectConfig::fromArray([
                'project' => 'learnhub',
                'source_locale' => 'en_GB',
                'files' => ['pattern' => 'translations/messages.{lang}.{format}'],
            ]);
            self::fail('Nieznany placeholder ma przewrócić wczytywanie konfiguracji.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('{format}', $e->getMessage(), 'komunikat nazywa winowajcę');
            self::assertStringContainsString('{ns}', $e->getMessage(), 'i podaje, co wolno wpisać');
        }
    }

    public function testTheDownloadOptionsForSortingAndNestingAreReadFromTheFile(): void
    {
        $this->writeYaml(<<<'YAML'
        project: learnhub
        source_locale: en_GB
        files:
          pattern: "translations/messages.{lang}.json"
          format: json
        download:
          sort: key
          write_nested: true
        YAML);

        $config = $this->store()->load();

        self::assertNotNull($config);
        self::assertSame('key', $config->downloadSort);
        self::assertTrue($config->downloadWriteNested);

        $options = $config->writeOptions();
        self::assertTrue($options->nested, 'konfiguracja staje się zachowaniem w jednym miejscu (`writeOptions`)');
        self::assertSame(LocaleWriteOptions::SORT_KEY, $options->sort);
    }

    public function testTheDefaultDownloadOptionsPreserveTheFileAsItIs(): void
    {
        $config = ProjectConfig::fromArray([
            'project' => 'learnhub',
            'source_locale' => 'en_GB',
            'files' => ['pattern' => 'translations/messages.{lang}.php'],
        ]);

        self::assertSame(
            LocaleWriteOptions::SORT_FILE,
            $config->downloadSort,
            'domyślnie kolejność z pliku — `pull`, który tasuje cudzy plik, to problem, od którego odchodzimy',
        );
        self::assertFalse($config->downloadWriteNested, 'struktura bierze się z pliku, chyba że zespół powie inaczej');
    }

    public function testASortValueOutsideTheClosedListIsRejected(): void
    {
        $this->writeYaml(<<<'YAML'
        project: learnhub
        source_locale: en_GB
        files:
          pattern: "translations/messages.{lang}.php"
        download:
          sort: alphabetical
        YAML);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/download.sort/');

        $this->store()->load();
    }

    /**
     * `upload.unapprove_changed` i `download.post_process_command` — obie decyzje z 1.19.
     *
     * Pierwsza odpowiada na pytanie „czy zmiana tekstu źródłowego unieważnia czyjeś
     * zatwierdzenie”, druga pozwala dociągnąć formatowanie własnym skryptem projektu.
     */
    public function testTheUploadAndDownloadHooksFromItem119AreReadFromTheFile(): void
    {
        $this->writeYaml(<<<'YAML'
        project: learnhub
        source_locale: en_GB
        files:
          pattern: "translations/messages.{lang}.php"
        upload:
          unapprove_changed: true
        download:
          post_process_command: "php bin/format-translations"
        YAML);

        $config = $this->store()->load();

        self::assertNotNull($config);
        self::assertTrue($config->uploadUnapproveChanged);
        self::assertSame('php bin/format-translations', $config->downloadPostProcessCommand);
    }

    public function testBothHooksAreOffByDefault(): void
    {
        $config = ProjectConfig::fromArray([
            'project' => 'learnhub',
            'source_locale' => 'en_GB',
            'files' => ['pattern' => 'translations/messages.{lang}.php'],
        ]);

        self::assertFalse(
            $config->uploadUnapproveChanged,
            'domyślnie zatwierdzenia przeżywają zmianę źródła: literówka nie może cofać czyjejś pracy',
        );
        self::assertNull($config->downloadPostProcessCommand, 'brak hook-a to brak komendy, nie pusta komenda');
    }

    public function testAnEmptyPostProcessCommandMeansNoHook(): void
    {
        $config = ProjectConfig::fromArray([
            'project' => 'learnhub',
            'source_locale' => 'en_GB',
            'files' => ['pattern' => 'translations/messages.{lang}.php'],
            'download' => ['post_process_command' => '   '],
        ]);

        self::assertNull($config->downloadPostProcessCommand, 'spacje to nie komenda — inaczej CLI próbowałoby ją uruchomić');
    }

    public function testANonStringPostProcessCommandIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/post_process_command/');

        ProjectConfig::fromArray([
            'project' => 'learnhub',
            'source_locale' => 'en_GB',
            'files' => ['pattern' => 'translations/messages.{lang}.php'],
            'download' => ['post_process_command' => ['php', 'bin/format']],
        ]);
    }

    public function testAnEmptyChecksRunListIsRejected(): void
    {
        $this->writeYaml(<<<'YAML'
        project: learnhub
        source_locale: en_GB
        files:
          pattern: "translations/messages.{lang}.php"
        checks:
          run: []
        YAML);

        try {
            $this->store()->load();
            self::fail('Pusta lista kontroli ma przewrócić wczytywanie konfiguracji.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('checks.run', $e->getMessage(), 'komunikat nazywa pole');
            self::assertStringContainsString('quality', $e->getMessage(), 'i mówi, jak wygląda zestaw domyślny');
        }
    }

    public function testAnUnknownCheckNameInTheListIsRejectedWithTheAllowedNames(): void
    {
        $this->writeYaml(<<<'YAML'
        project: learnhub
        source_locale: en_GB
        files:
          pattern: "translations/messages.{lang}.php"
        checks:
          run: [quality, coveragee]
        YAML);

        try {
            $this->store()->load();
            self::fail('Literówka w nazwie kontroli ma przewrócić wczytywanie konfiguracji.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('coveragee', $e->getMessage());
            self::assertStringContainsString(
                'acceptance',
                $e->getMessage(),
                'komunikat wypisuje zamkniętą listę nazw — inaczej człowiek zgaduje, co wolno wpisać',
            );
        }
    }

    /**
     * Powtórzona kontrola to drugie żądanie i druga porcja tych samych ustaleń w jednym
     * uruchomieniu — czyli szum, przez który przestaje się czytać log bramki.
     */
    public function testARepeatedCheckInTheListIsRejected(): void
    {
        $this->writeYaml(<<<'YAML'
        project: learnhub
        source_locale: en_GB
        files:
          pattern: "translations/messages.{lang}.php"
        checks:
          run: [quality, quality]
        YAML);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/powtórzona/');

        $this->store()->load();
    }

    public function testAMalformedYamlFileSaysWhereTheErrorIs(): void
    {
        file_put_contents($this->root . '/.babelhub.yml', "project: learnhub\n  source_locale: en_GB\n");

        try {
            $this->store()->load();
            self::fail('Zły YAML ma przewrócić wczytywanie konfiguracji.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('.babelhub.yml', $e->getMessage(), 'komunikat ma wskazać plik');
            self::assertStringContainsString('YAML', $e->getMessage(), 'i powiedzieć, że to błąd składni YAML');
        }
    }

    /**
     * Dwa pliki konfiguracji w jednym katalogu to **błąd**, a nie „jeden wygrywa”: dwa źródła
     * prawdy o tym, co jedzie do serwisu, rozjeżdżają się przy pierwszej zmianie, a objawia się
     * to nie komunikatem, tylko tym, że push wysyła nie to, co trzeba.
     */
    public function testBothConfigurationFilesInOneDirectoryAreRejected(): void
    {
        $store = $this->store();
        $store->save($this->config('learnhub'), $this->root);
        file_put_contents($this->root . '/.babelhub.yml', "project: learnhub\nsource_locale: en_GB\nfiles:\n  pattern: \"translations/messages.{lang}.php\"\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/oba pliki/');

        $store->locate();
    }

    public function testTheStoreWritesYamlWhenAskedAndLoadsItBack(): void
    {
        $store = $this->store();
        $path = $store->save($this->config('learnhub'), $this->root, ProjectConfig::FILE_NAME_YAML);

        self::assertSame($this->root . '/.babelhub.yml', $path);
        $raw = (string) file_get_contents($path);
        self::assertStringContainsString('project: learnhub', $raw, 'to ma być YAML, a nie JSON w pliku .yml');
        self::assertStringNotContainsString('"project"', $raw, 'klucze YAML nie są cytowane jak w JSON-ie');

        $loaded = $store->load();
        self::assertNotNull($loaded);
        self::assertSame('learnhub', $loaded->project);
        self::assertSame($this->config('learnhub')->scanPaths, $loaded->scanPaths);
    }

    public function testYamlRoundTripsEveryFieldIncludingTheNewSections(): void
    {
        $config = new ProjectConfig(
            project: 'learnhub',
            sourceLocale: 'en_GB',
            filePattern: 'translations/messages.{lang}.php',
            fileFormat: ProjectConfig::FORMAT_PHP,
            scanPaths: ['src'],
            uploadMarkMissingAsRemoved: false,
            downloadLocales: ['pl_PL', 'de_DE'],
            checksFailOn: 'warning',
            checksRun: [CheckName::Coverage, CheckName::Acceptance],
            checksIgnore: ['src/Legacy'],
        );

        $this->store()->save($config, $this->root, ProjectConfig::FILE_NAME_YAML);
        $loaded = $this->store()->load();

        self::assertNotNull($loaded);
        self::assertFalse($loaded->uploadMarkMissingAsRemoved);
        self::assertSame(['pl_PL', 'de_DE'], $loaded->downloadLocales, 'kolejność języków z pliku jest zachowana');
        self::assertSame('warning', $loaded->checksFailOn);
        self::assertSame([CheckName::Coverage, CheckName::Acceptance], $loaded->checksRun, 'zestaw kontroli przechodzi przez zapis i odczyt');
        self::assertSame(['src/Legacy'], $loaded->checksIgnore, 'wyjątki kontroli lokalnej też — plik pokazuje wszystkie ustawienia');
    }

    public function testAMissingProjectNamesTheField(): void
    {
        $this->assertRejected(
            ['source_locale' => 'en_GB', 'files' => ['pattern' => 'translations/messages.{lang}.php']],
            '"project"',
        );
    }

    public function testAMissingSourceLocaleNamesTheField(): void
    {
        $this->assertRejected(
            ['project' => 'learnhub', 'files' => ['pattern' => 'translations/messages.{lang}.php']],
            '"source_locale"',
        );
    }

    public function testAMissingFilesSectionNamesTheField(): void
    {
        $this->assertRejected(['project' => 'learnhub', 'source_locale' => 'en_GB'], '"files"');
    }

    public function testAPatternWithoutTheLanguagePlaceholderIsRejected(): void
    {
        // Bez `{lang}` pull zapisałby wszystkie języki do jednego pliku i ostatni wygrał.
        $this->assertRejected([
            'project' => 'learnhub',
            'source_locale' => 'en_GB',
            'files' => ['pattern' => 'translations/messages.php'],
        ], 'files.pattern');
    }

    public function testAnUnknownFileFormatIsRejected(): void
    {
        // „Format z przyszłości” to format, którego `pull` nie umie zapisać — więc jest błędem.
        $this->assertRejected([
            'project' => 'learnhub',
            'source_locale' => 'en_GB',
            'files' => ['pattern' => 'translations/messages.{lang}.yaml', 'format' => 'yaml'],
        ], 'files.format');
    }

    public function testAnUnknownRootKeyIsRejectedInsteadOfBeingIgnored(): void
    {
        $this->assertRejected([
            'project' => 'learnhub',
            'source_locale' => 'en_GB',
            'files' => ['pattern' => 'translations/messages.{lang}.php'],
            'sourcelocale' => 'en_GB',
        ], 'sourcelocale');
    }

    public function testAnUnknownKeyInsideFilesIsRejectedToo(): void
    {
        $this->assertRejected([
            'project' => 'learnhub',
            'source_locale' => 'en_GB',
            'files' => ['pattern' => 'translations/messages.{lang}.php', 'sort' => true],
        ], 'sort');
    }

    public function testScanPathsThatAreNotAListAreRejected(): void
    {
        // Obiekt (`{"src": true}`) wygląda w JSON-ie jak tablica, ale listą nie jest.
        $this->assertRejected([
            'project' => 'learnhub',
            'source_locale' => 'en_GB',
            'files' => ['pattern' => 'translations/messages.{lang}.php'],
            'scan' => ['paths' => ['src' => true]],
        ], 'scan.paths');
    }

    public function testAScanSectionThatIsNotAnObjectIsRejected(): void
    {
        $this->assertRejected([
            'project' => 'learnhub',
            'source_locale' => 'en_GB',
            'files' => ['pattern' => 'translations/messages.{lang}.php'],
            'scan' => 'src',
        ], 'scan');
    }

    public function testFileForSubstitutesTheLanguageAndRespectsTheRoot(): void
    {
        $config = new ProjectConfig(
            project: 'learnhub',
            sourceLocale: 'en_GB',
            filePattern: 'translations/messages.{lang}.php',
            fileFormat: ProjectConfig::FORMAT_PHP,
        );

        self::assertSame('translations/messages.pl_PL.php', $config->fileFor('pl_PL'));
        self::assertSame(
            $this->root . '/translations/messages.pl_PL.php',
            $config->fileFor('pl_PL', $this->root),
            'korzeń jest doklejany raz, bez podwójnego separatora',
        );
        self::assertSame(
            $this->root . '/translations/messages.pl_PL.php',
            $config->fileFor('pl_PL', $this->root . '/'),
            'końcowy separator korzenia nie może dawać „//” w ścieżce',
        );
    }

    public function testToArrayRoundTripsThroughFromArray(): void
    {
        $config = new ProjectConfig(
            project: 'learnhub',
            sourceLocale: 'pl_PL',
            filePattern: 'translations/messages.{lang}.json',
            fileFormat: ProjectConfig::FORMAT_JSON,
            scanPaths: ['src', 'templates'],
        );

        $encoded = $config->toArray();

        self::assertArrayHasKey('$schema', $encoded, 'edytory podpowiadają pola na podstawie $schema');
        self::assertSame($config->project, ProjectConfig::fromArray($encoded)->project);
        self::assertSame($config->sourceLocale, ProjectConfig::fromArray($encoded)->sourceLocale);
        self::assertSame($config->fileFormat, ProjectConfig::fromArray($encoded)->fileFormat);
        self::assertSame($config->scanPaths, ProjectConfig::fromArray($encoded)->scanPaths);
    }

    public function testTheStoreFindsTheConfigurationWalkingUpFromANestedDirectory(): void
    {
        $config = $this->config('learnhub');
        (new ProjectConfigStore($this->root))->save($config, $this->root);

        $nested = $this->root . '/src/Sub/Deeper';
        mkdir($nested, 0777, true);

        $store = new ProjectConfigStore($nested);

        self::assertSame(
            $this->root,
            $store->locate(),
            'konfiguracja obowiązuje w całym drzewie poniżej — jak .git czy composer.json',
        );
        self::assertSame('learnhub', $store->load()?->project);
    }

    public function testTheStoreReturnsNullWhenThereIsNoConfigurationAnywhere(): void
    {
        $nested = $this->root . '/src/Sub';
        mkdir($nested, 0777, true);

        $store = new ProjectConfigStore($nested);

        self::assertNull($store->locate(), 'brak konfiguracji to brak — nie konfiguracja domyślna');
        self::assertNull($store->load());
    }

    public function testSaveOverwritesAtomicallyAndLeavesNoTemporaryFileBehind(): void
    {
        $store = new ProjectConfigStore($this->root);
        $path = $store->save($this->config('first.project'), $this->root);

        self::assertSame($this->root . '/.babelhub.json', $path);

        $store->save($this->config('second.project'), $this->root);

        $raw = (string) file_get_contents($path);
        self::assertStringContainsString('second.project', $raw);
        self::assertStringNotContainsString('first.project', $raw);
        self::assertFileDoesNotExist(
            $path . '.tmp',
            'plik tymczasowy po udanym rename nie może zostawać — inaczej trafia do commita',
        );
    }

    public function testWhatSaveWroteIsParseableAndRoundTrips(): void
    {
        $config = new ProjectConfig(
            project: 'learnhub',
            sourceLocale: 'pl_PL',
            filePattern: 'translations/messages.{lang}.json',
            fileFormat: ProjectConfig::FORMAT_JSON,
            scanPaths: ['src'],
        );

        $path = (new ProjectConfigStore($this->root))->save($config, $this->root);
        $raw = (string) file_get_contents($path);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        $again = ProjectConfig::fromArray($decoded);

        self::assertSame('learnhub', $again->project);
        self::assertSame('pl_PL', $again->sourceLocale);
        self::assertSame(['src'], $again->scanPaths);
        self::assertStringNotContainsString(
            '\\/',
            $raw,
            'JSON_UNESCAPED_SLASHES: ścieżki w pliku konfiguracji mają wyglądać jak ścieżki',
        );
    }

    public function testPathInAndExistsInPointAtTheFileInTheGivenDirectory(): void
    {
        $directory = $this->root . '/projekt';
        mkdir($directory, 0777, true);

        $store = new ProjectConfigStore($this->root);

        self::assertSame($directory . '/.babelhub.json', $store->pathIn($directory));
        self::assertSame($directory . '/.babelhub.json', $store->pathIn($directory . '/'));
        self::assertFalse($store->existsIn($directory));

        $store->save($this->config('learnhub'), $directory);

        self::assertTrue($store->existsIn($directory));
    }

    private function config(string $project): ProjectConfig
    {
        return new ProjectConfig(
            project: $project,
            sourceLocale: 'en_GB',
            filePattern: 'translations/messages.{lang}.php',
            fileFormat: ProjectConfig::FORMAT_PHP,
            scanPaths: ['src'],
        );
    }

    /** @param array<string, mixed> $data */
    private function assertRejected(array $data, string $fieldInMessage): void
    {
        try {
            ProjectConfig::fromArray($data);
        } catch (RuntimeException $e) {
            self::assertStringContainsString(
                $fieldInMessage,
                $e->getMessage(),
                'komunikat musi nazwać pole — inaczej człowiek szuka błędu wzrokiem',
            );

            return;
        }

        self::fail(sprintf('Niepoprawny kształt konfiguracji został przyjęty (oczekiwałem wzmianki o %s).', $fieldInMessage));
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
