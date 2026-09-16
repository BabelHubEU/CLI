<?php

declare(strict_types=1);

namespace Tests\Cli;

use BabelHub\Cli\Config\ProjectConfig;
use BabelHub\Cli\Files\LocaleFile;
use BabelHub\Cli\Files\LocaleWriteOptions;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Plik językowy projektu: **round-trip bez strat** i atomowy zapis.
 *
 * Sens tego testu jest w jednym zdaniu z `LocaleFile`: piszemy to, co projekt ma, a nie to,
 * co nam wygodniej. Dlatego sprawdzamy **wartości po odczycie** (unicode, apostrof, znak nowej
 * linii), a nie obecność napisu w pliku: własny escaper psuje dokładnie te trzy rzeczy i psuje
 * je cicho — tłumaczenie z apostrofem (`It's done`) albo z `\n` wygląda wtedy poprawnie
 * w podglądzie, a w aplikacji jest ucięte.
 *
 * Drugi temat to atomowość zapisu: `pull` nadpisuje pliki, które są w repozytorium, więc
 * przerwany zapis trafiłby do commita jako „plik się zmienił” — z połową treści.
 */
final class LocaleFileTest extends TestCase
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

    public function testThePhpRoundTripKeepsUnicodeApostrophesAndNewlines(): void
    {
        $path = $this->root . '/translations/messages.pl_PL.php';
        $messages = [
            'greeting' => 'Zażółć gęślą jaźń',
            'apostrophe' => "It's done",
            'multiline' => "Pierwsza linia\nDruga linia",
            'quotes' => 'Powiedział "tak" i tyle',
        ];

        $file = new LocaleFile(ProjectConfig::FORMAT_PHP);
        $file->write($path, $messages);

        self::assertSame($messages, $file->read($path), 'odczyt musi dać dokładnie to, co zapisaliśmy');
        self::assertStringContainsString('<?php', (string) file_get_contents($path));
    }

    public function testTheJsonRoundTripKeepsUnicodeAndSlashes(): void
    {
        $path = $this->root . '/translations/messages.pl_PL.json';
        $messages = [
            'url' => 'https://babelhub.dev/panel?a=1',
            'greeting' => 'Zażółć gęślą jaźń',
            'apostrophe' => "It's done",
        ];

        $file = new LocaleFile(ProjectConfig::FORMAT_JSON);
        $file->write($path, $messages);

        self::assertSame($messages, $file->read($path));

        $raw = (string) file_get_contents($path);
        self::assertStringContainsString('Zażółć gęślą jaźń', $raw, 'JSON_UNESCAPED_UNICODE: plik ma być czytelny w review');
        self::assertStringContainsString('https://babelhub.dev/panel?a=1', $raw, 'JSON_UNESCAPED_SLASHES: bez `\\/`');
    }

    /**
     * Plik zagnieżdżony czytamy jako klucze płaskie — i to jest warunek dwóch rzeczy naraz:
     * `push` ma jeden słownik tekstów źródłowych niezależnie od struktury pliku, a `pull`
     * **wie**, że plik był zagnieżdżony, więc nie zamieni go na płaski (1.16b).
     */
    public function testANestedFileIsReadAsFlatKeys(): void
    {
        $path = $this->root . '/messages.en_GB.json';
        file_put_contents($path, "{\n  \"nav\": {\n    \"title\": \"Menu\",\n    \"sub\": {\"deep\": \"Głęboko\"}\n  },\n  \"plain\": \"Prosto\"\n}\n");

        self::assertSame(
            ['nav.title' => 'Menu', 'nav.sub.deep' => 'Głęboko', 'plain' => 'Prosto'],
            (new LocaleFile(ProjectConfig::FORMAT_JSON))->read($path),
            'kolejność z pliku też jest zachowana — od niej zależy kolejność w zapisie',
        );
    }

    public function testANonStringLeafIsRejectedWithTheFullKeyAndItsType(): void
    {
        $path = $this->root . '/messages.en_GB.php';
        file_put_contents($path, "<?php\n\nreturn [\n    'nav' => ['count' => 3],\n];\n");

        try {
            (new LocaleFile(ProjectConfig::FORMAT_PHP))->read($path);
            self::fail('Liczba w pliku językowym ma przewrócić czytanie.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('nav.count', $e->getMessage(), 'komunikat podaje pełny klucz, nie sam liść');
            self::assertStringContainsString('int', $e->getMessage(), 'i mówi, czym ta wartość jest');
        }
    }

    public function testAJsonValueThatIsNotAStringIsRejectedWithTheKeyName(): void
    {
        $path = $this->root . '/messages.en_GB.json';
        file_put_contents($path, "{\n    \"ok\": \"tekst\",\n    \"count\": 3\n}\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/count/');

        (new LocaleFile(ProjectConfig::FORMAT_JSON))->read($path);
    }

    public function testAPhpFileThatDoesNotReturnAnArrayIsRejected(): void
    {
        $path = $this->root . '/messages.en_GB.php';
        file_put_contents($path, "<?php\n\nreturn 'to nie jest tablica';\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/nie zwraca tablicy/');

        (new LocaleFile(ProjectConfig::FORMAT_PHP))->read($path);
    }

    public function testAPhpFileThatBlowsUpWhileBeingIncludedIsReportedAsALocaleFileProblem(): void
    {
        $path = $this->root . '/messages.en_GB.php';
        file_put_contents($path, "<?php\n\nreturn [\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/nie dał się wczytać/');

        (new LocaleFile(ProjectConfig::FORMAT_PHP))->read($path);
    }

    public function testJsonThatIsNotJsonIsRejected(): void
    {
        $path = $this->root . '/messages.en_GB.json';
        file_put_contents($path, "{to nie jest json");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/nie jest poprawnym JSON-em/');

        (new LocaleFile(ProjectConfig::FORMAT_JSON))->read($path);
    }

    public function testReadingAMissingFileSaysWhichFileIsMissing(): void
    {
        $path = $this->root . '/translations/messages.en_GB.php';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Nie ma pliku/');

        (new LocaleFile(ProjectConfig::FORMAT_PHP))->read($path);
    }

    public function testWriteCreatesMissingDirectories(): void
    {
        $path = $this->root . '/deep/er/translations/messages.en_GB.json';

        (new LocaleFile(ProjectConfig::FORMAT_JSON))->write($path, ['a.one' => 'One']);

        self::assertFileExists($path);
        self::assertDirectoryExists(dirname($path));
    }

    public function testWriteIsAtomicAndLeavesNoTemporaryFileBehind(): void
    {
        $path = $this->root . '/messages.en_GB.php';
        $file = new LocaleFile(ProjectConfig::FORMAT_PHP);

        $file->write($path, ['a.one' => 'Pierwsza wersja']);
        $file->write($path, ['a.one' => 'Druga wersja']);

        self::assertSame(['a.one' => 'Druga wersja'], $file->read($path), 'drugi zapis nadpisuje pierwszy');
        self::assertFileDoesNotExist(
            $path . '.tmp',
            'plik tymczasowy zostaje tylko po przerwanym zapisie — a wtedy trafia do commita',
        );
    }

    // -----------------------------------------------------------------
    // 1.16b: piszemy w formacie, w jakim plik już jest
    // -----------------------------------------------------------------

    /**
     * Końce linii, wcięcia i końcowy znak nowej linii **z pliku**, nie z naszych upodobań.
     *
     * To nie kosmetyka: plik z CRLF przepisany na LF, albo z dwoma spacjami na cztery, daje diff
     * w każdym wierszu. Review takiego pull requesta przestaje cokolwiek znaczyć, a `git blame`
     * wskazuje na narzędzie zamiast na autora zmiany.
     */
    public function testWritingKeepsTheEolIndentAndFinalNewlineOfTheExistingFile(): void
    {
        $path = $this->root . '/messages.pl_PL.json';
        file_put_contents($path, "{\r\n  \"a.one\": \"Stare\"\r\n}");

        (new LocaleFile(ProjectConfig::FORMAT_JSON))->write($path, ['a.one' => 'Nowe']);

        $raw = (string) file_get_contents($path);

        self::assertStringContainsString("\r\n", $raw, 'CRLF zostaje CRLF');
        self::assertStringNotContainsString("\n  \"a.one\"", str_replace("\r\n", '', $raw), 'wcięcie to dwie spacje, nie cztery');
        self::assertStringContainsString("{\r\n  \"a.one\": \"Nowe\"\r\n}", $raw, 'bez końcowego znaku nowej linii, bo plik go nie miał');
    }

    public function testWritingKeepsTheKeyOrderAndAppendsNewKeysAtTheEnd(): void
    {
        $path = $this->root . '/messages.pl_PL.php';
        file_put_contents($path, "<?php\n\nreturn [\n    'z.last' => 'Ostatni',\n    'a.first' => 'Pierwszy',\n];\n");

        (new LocaleFile(ProjectConfig::FORMAT_PHP))->write($path, [
            'a.first' => 'Pierwszy (nowy)',
            'm.middle' => 'Środkowy',
            'z.last' => 'Ostatni',
        ]);

        $keys = array_keys((new LocaleFile(ProjectConfig::FORMAT_PHP))->read($path));

        self::assertSame(
            ['z.last', 'a.first', 'm.middle'],
            $keys,
            'istniejące klucze zostają na miejscach, nowy dochodzi na końcu — diff pokazuje dokładnie to, co doszło',
        );
    }

    /**
     * **Klucze nieznane katalogowi zostają w pliku.** Plik językowy w repozytorium bywa ręcznie
     * uzupełniany (tekst jeszcze niezatwierdzony, klucz dodany lokalnie), a `pull` nie jest jego
     * właścicielem — skasowanie cudzej pracy bez pytania to najdroższy błąd tego narzędzia.
     */
    public function testKeysTheCatalogDoesNotKnowStayInTheFile(): void
    {
        $path = $this->root . '/messages.pl_PL.json';
        file_put_contents($path, "{\n    \"local.only\": \"Tylko lokalnie\",\n    \"a.one\": \"Stare\"\n}\n");

        (new LocaleFile(ProjectConfig::FORMAT_JSON))->write($path, ['a.one' => 'Nowe']);

        self::assertSame(
            ['local.only' => 'Tylko lokalnie', 'a.one' => 'Nowe'],
            (new LocaleFile(ProjectConfig::FORMAT_JSON))->read($path),
        );
    }

    public function testANestedFileStaysNestedAndAFlatFileStaysFlat(): void
    {
        $nested = $this->root . '/nested.json';
        file_put_contents($nested, "{\n  \"nav\": {\n    \"title\": \"Menu\"\n  }\n}\n");

        $flat = $this->root . '/flat.json';
        file_put_contents($flat, "{\n  \"nav.title\": \"Menu\"\n}\n");

        $file = new LocaleFile(ProjectConfig::FORMAT_JSON);
        $file->write($nested, ['nav.title' => 'Menu', 'nav.sub' => 'Podmenu']);
        $file->write($flat, ['nav.title' => 'Menu', 'nav.sub' => 'Podmenu']);

        self::assertStringContainsString('"nav": {', (string) file_get_contents($nested), 'struktura z pliku jest zachowana');
        self::assertStringContainsString('"sub": "Podmenu"', (string) file_get_contents($nested), 'nowy klucz dochodzi w tej samej strukturze');
        self::assertStringContainsString('"nav.title"', (string) file_get_contents($flat));
        self::assertStringNotContainsString('"nav": {', (string) file_get_contents($flat), 'płaski plik zostaje płaski');
    }

    public function testWriteNestedForcesTheNestedShapeForANewFile(): void
    {
        $path = $this->root . '/new.json';

        (new LocaleFile(ProjectConfig::FORMAT_JSON))->write(
            $path,
            ['nav.title' => 'Menu', 'nav.sub.deep' => 'Głęboko'],
            new LocaleWriteOptions(nested: true),
        );

        self::assertSame(
            "{\n    \"nav\": {\n        \"title\": \"Menu\",\n        \"sub\": {\n            \"deep\": \"Głęboko\"\n        }\n    }\n}\n",
            (string) file_get_contents($path),
        );
    }

    public function testSortKeyOrdersEverythingAlphabetically(): void
    {
        $path = $this->root . '/messages.pl_PL.php';
        file_put_contents($path, "<?php\n\nreturn [\n    'z.last' => 'Ostatni',\n];\n");

        (new LocaleFile(ProjectConfig::FORMAT_PHP))->write(
            $path,
            ['z.last' => 'Ostatni', 'a.first' => 'Pierwszy', 'm.middle' => 'Środkowy'],
            new LocaleWriteOptions(sort: LocaleWriteOptions::SORT_KEY),
        );

        self::assertSame(
            ['a.first', 'm.middle', 'z.last'],
            array_keys((new LocaleFile(ProjectConfig::FORMAT_PHP))->read($path)),
            '`sort: key` to decyzja zespołu — wygrywa z kolejnością pliku',
        );
    }

    /**
     * `declare(strict_types=1)` i `array()` to decyzje projektu PHP, nie nasze. Dopisanie
     * pierwszego albo zamiana drugiego na `[]` to dokładnie „poprawianie po swojemu”, którego
     * plan zabrania (1.16b).
     */
    public function testPhpStyleKeepsStrictTypesAndArraySyntax(): void
    {
        $legacy = $this->root . '/legacy.php';
        file_put_contents($legacy, "<?php\n\nreturn array(\n\t'a.one' => 'Stare',\n);\n");

        $modern = $this->root . '/modern.php';
        file_put_contents($modern, "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n    'a.one' => 'Stare',\n];\n");

        $file = new LocaleFile(ProjectConfig::FORMAT_PHP);
        $file->write($legacy, ['a.one' => 'Nowe']);
        $file->write($modern, ['a.one' => 'Nowe']);

        $legacyRaw = (string) file_get_contents($legacy);
        self::assertStringNotContainsString('strict_types', $legacyRaw, 'nie dokładamy deklaracji, której plik nie miał');
        self::assertStringContainsString('return array(', $legacyRaw, '`array()` zostaje `array()`');
        self::assertStringContainsString("\t'a.one'", $legacyRaw, 'tabulator zostaje tabulatorem');

        self::assertStringContainsString('strict_types', (string) file_get_contents($modern));
        self::assertStringContainsString('return [', (string) file_get_contents($modern));
    }

    /**
     * **Zepsuty plik nie blokuje `pull`.** Kolejności ani treści nie da się z niego odczytać,
     * więc jedyne, co moglibyśmy zrobić, to odmówić pracy — dokładnie w momencie, w którym
     * `pull` jest najbardziej potrzebny (plik jest zepsuty i trzeba go naprawić).
     */
    public function testABrokenExistingFileIsRepairedInsteadOfBlockingTheWrite(): void
    {
        $path = $this->root . '/messages.pl_PL.json';
        file_put_contents($path, "{to nie jest json");

        (new LocaleFile(ProjectConfig::FORMAT_JSON))->write($path, ['a.one' => 'Nowe']);

        self::assertSame(['a.one' => 'Nowe'], (new LocaleFile(ProjectConfig::FORMAT_JSON))->read($path));
        self::assertStringContainsString("{\n    \"a.one\": \"Nowe\"\n}\n", (string) file_get_contents($path));
    }

    /**
     * Kolizja „klucz jest i liściem, i gałęzią” (`nav` oraz `nav.title`) w naszym katalogu nie
     * występuje, ale plik klienta może tak wyglądać. Wtedy dłuższy klucz zostaje **pod pełną
     * nazwą** — ciche zgubienie tekstu byłoby najgorszym możliwym rozwiązaniem.
     */
    public function testTheNestedShapeKeepsALongerKeyWhenAParentIsALeaf(): void
    {
        $path = $this->root . '/collision.json';

        (new LocaleFile(ProjectConfig::FORMAT_JSON))->write(
            $path,
            ['nav' => 'Nawigacja', 'nav.title' => 'Menu'],
            new LocaleWriteOptions(nested: true),
        );

        self::assertSame(
            ['nav' => 'Nawigacja', 'nav.title' => 'Menu'],
            (new LocaleFile(ProjectConfig::FORMAT_JSON))->read($path),
            'oba teksty są w pliku — jeden jako liść, drugi pod pełnym kluczem',
        );
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
