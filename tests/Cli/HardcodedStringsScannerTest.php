<?php

declare(strict_types=1);

namespace Tests\Cli;

use BabelHub\Cli\Checks\HardcodedStringsScanner;
use PHPUnit\Framework\TestCase;

/**
 * Kontrola „stringi zakodowane na sztywno” (pozycja 1.13) — **heurystyka, więc test mierzy
 * głównie to, czego NIE wolno zgłosić**.
 *
 * Każda reguła, która potrafi zapalić się na poprawnym kodzie, jest gorsza niż jej brak: pierwszy
 * fałszywy alarm uczy ludzi ignorować kontrolę, a wtedy kontrola przestaje istnieć (ta sama zasada
 * co przy QA z serwisu). Dlatego obok przypadków „to jest tekst dla człowieka” stoją tu
 * przypadki-techniczne: klucze tłumaczeń, komentarze, ścieżki, URL-e, nazwy nagłówków HTTP,
 * komunikaty dla developera i szablony już przetłumaczone.
 *
 * Testy chodzą po plikach w katalogu tymczasowym, bo kontrola czyta **kod z dysku** — a nie
 * po atrapie, która zawsze powie to, co jej każemy.
 */
final class HardcodedStringsScannerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/babelhub-cli-test-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0777, true);
        mkdir($this->root . '/templates', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);

        parent::tearDown();
    }

    public function testItFindsTextThatOnlyLooksLikeInterfaceCopy(): void
    {
        $this->write('src/Panel.php', <<<'PHP'
        <?php

        $title = 'Welcome back to the dashboard';
        echo $title;
        PHP);

        $findings = $this->scan();

        self::assertCount(1, $findings);
        self::assertSame('hardcoded_string', $findings[0]->code);
        self::assertSame('warning', $findings[0]->severity->value, 'heurystyka nie blokuje merge domyślnie');
        self::assertSame('src/Panel.php', $findings[0]->file);
        self::assertSame(3, $findings[0]->line);
        self::assertSame('Welcome back to the dashboard', $findings[0]->text);
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function neverReported(): iterable
    {
        yield 'klucz tłumaczenia w trans()' => ["<?php\n\ntrans('about.us.title');\n", 'src/A.php'];
        yield 'tekst już tłumaczony' => ["<?php\n\n\$t->trans('Sign in to continue');\n", 'src/B.php'];
        yield 'komentarz w PHP' => ["<?php\n\n// This is a comment for developers only\n/* Another block comment here */\n", 'src/C.php'];
        yield 'ścieżka i URL' => ["<?php\n\n\$p = 'translations/messages.en_GB.php';\n\$u = 'https://example.com/some page';\n", 'src/D.php'];
        yield 'nazwa nagłówka HTTP' => ["<?php\n\n\$h = 'Content-Type: application/json';\n", 'src/E.php'];
        yield 'komunikat dla developera' => ["<?php\n\nthrow new \\RuntimeException('Nie mogę wczytać konfiguracji projektu.');\n", 'src/F.php'];
        yield 'zapytanie SQL' => ["<?php\n\n\$sql = 'SELECT id FROM users WHERE active = 1';\n", 'src/G.php'];
        yield 'pojedyncze słowo' => ["<?php\n\n\$label = 'Locales';\n", 'src/H.php'];
        yield 'nazwa klasy' => ["<?php\n\n\$c = 'DoctrineTranslationKeyRepository';\n", 'src/I.php'];
        yield 'identyfikator z kropkami' => ["<?php\n\n\$k = 'about.us.title';\n", 'src/J.php'];
        yield 'tekst zaczynający się z małej litery' => ["<?php\n\n\$log = 'could not connect to the service';\n", 'src/K.php'];
        yield 'szablon przetłumaczony' => ["<h1>{{ 'dashboard.title'|trans }}</h1>\n", 'templates/a.twig'];
        yield 'tekst w komentarzu Twiga' => ["{# This heading waits for a designer #}\n<h1>Hi</h1>\n", 'templates/b.twig'];
        yield 'literał w bloku kodu Twiga' => ["{% set label = 'Draft preview mode' %}\n<span>{{ label }}</span>\n", 'templates/c.twig'];
        yield 'etykieta bez spacji' => ["<th>Slug</th>\n", 'templates/d.twig'];
        yield 'tekst w ignore' => ["<?php\n\n\$title = 'Welcome back to the dashboard';\n", 'src/Legacy/L.php'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('neverReported')]
    public function testItDoesNotReportTechnicalStrings(string $contents, string $file): void
    {
        $this->write($file, $contents);

        $findings = $this->scan($file === 'src/Legacy/L.php' ? ['src/Legacy'] : []);

        self::assertSame(
            [],
            array_map(static fn ($f): string => $f->location() . ' „' . $f->text . '”', $findings),
            'fałszywy alarm kosztuje więcej niż brak trafienia — kontrola, która go daje, przestaje być czytana',
        );
    }

    public function testItFindsPlainTextInATemplateWithTheExactLineNumber(): void
    {
        $this->write('templates/page.twig', implode("\n", [
            '{# komentarz #}',
            '<div class="card">',
            '    <h1>Welcome to your account</h1>',
            '    <p>{{ user.name }}</p>',
            '</div>',
        ]) . "\n");

        $findings = $this->scan();

        self::assertCount(1, $findings);
        self::assertSame(3, $findings[0]->line, 'numer linii musi wskazywać **oryginalny** plik');
        self::assertSame('Welcome to your account', $findings[0]->text);
    }

    public function testALineWithCodeOnlyIsNotReported(): void
    {
        $this->write('templates/list.twig', "<ul>\n{% for item in items %}\n<li>{{ item.label }}</li>\n{% endfor %}\n</ul>\n");

        self::assertSame([], $this->scan());
    }

    /**
     * Tekst **między** blokami Twiga to treść szablonu, a nie kod — nawet jeśli stoi w jednej
     * linii z `{% if %}`. Pierwsza wersja mojego testu oczekiwała, że kontrola to pominie;
     * kontrola miała rację, a test nie: `{% if app.debug %}Draft preview mode{% endif %}`
     * wypisuje ten tekst użytkownikowi, więc jest dokładnie tym, czego szukamy.
     */
    public function testTextBetweenTwigBlocksInOneLineIsStillOutput(): void
    {
        $this->write('templates/banner.twig', "{% if app.debug %}Draft preview mode{% endif %}\n");

        $findings = $this->scan();

        self::assertCount(1, $findings);
        self::assertSame('Draft preview mode', $findings[0]->text);
    }

    public function testIgnoredPathsAreSkippedEntirely(): void
    {
        $this->write('src/Legacy/Old.php', "<?php\n\n\$x = 'Welcome to the old screen';\n");
        $this->write('src/New.php', "<?php\n\n\$x = 'Welcome to the new screen';\n");

        $withoutIgnore = $this->scan();
        self::assertCount(2, $withoutIgnore);

        $withIgnore = $this->scan(['src/Legacy']);
        self::assertCount(1, $withIgnore);
        self::assertSame('src/New.php', $withIgnore[0]->file);
    }

    public function testAMissingScanDirectoryThrowsInsteadOfReportingZeroProblems(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/nie istnieje/');

        (new HardcodedStringsScanner())->scan($this->root, ['nie-ma-takiego'], []);
    }

    public function testItScansOnlyPhpAndTwigFiles(): void
    {
        $this->write('src/notes.md', "Welcome to the markdown file\n");
        $this->write('src/styles.css', "/* Welcome to the stylesheet */\n");

        self::assertSame([], $this->scan());
    }

    /**
     * @param list<string> $ignore
     *
     * @return list<\BabelHub\Cli\Checks\CheckFinding>
     */
    private function scan(array $ignore = []): array
    {
        return (new HardcodedStringsScanner())->scan($this->root, ['src', 'templates'], $ignore);
    }

    private function write(string $relativePath, string $contents): void
    {
        $path = $this->root . '/' . $relativePath;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, $contents);
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
