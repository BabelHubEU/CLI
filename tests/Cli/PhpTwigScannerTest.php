<?php

declare(strict_types=1);

namespace Tests\Cli;

use BabelHub\Cli\Scan\PhpTwigScanner;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Skaner PHP/Twig: **co** znajduje, **gdzie** to znalazł i czego świadomie nie rusza.
 *
 * Trzy reguły, które ten test zamraża, bo każda z nich ma swoją cenę:
 *
 * * `path:line` przy każdym kluczu — bez tego „skąd ten klucz?” zostaje bez odpowiedzi,
 *   a to pierwsze pytanie przy każdym nieoczekiwanym wpisie;
 * * `vendor`/`node_modules`/`var`/`cache`/`.git` pomijane — tłumaczenia bibliotek nie są
 *   tłumaczeniami projektu, a ich skanowanie daje tysiące kluczy, których nikt nie może zmienić;
 * * **klucz dynamiczny nie daje wpisu** — fałszywy klucz jest gorszy niż brak klucza, bo trafia
 *   do serwisu jako nowy, pusty wpis i ktoś musi go ręcznie usunąć.
 */
final class PhpTwigScannerTest extends TestCase
{
    private string $root;
    private PhpTwigScanner $scanner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/babelhub-cli-test-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);

        $this->scanner = new PhpTwigScanner();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);

        parent::tearDown();
    }

    public function testAllFiveSupportedCallShapesAreFoundWithTheirLocation(): void
    {
        $this->write('src/Keys.php', implode("\n", [
            '<?php',
            '',
            "\$a = trans('php.plain');",
            "\$b = \$this->translator->trans('php.method');",
        ]) . "\n");

        $this->write('templates/page.twig', implode("\n", [
            "{{ 'twig.single'|trans }}",
            '{{ "twig.double"|trans }}',
            '{% trans %}twig.block{% endtrans %}',
        ]) . "\n");

        $result = $this->scanner->scan($this->root, ['src', 'templates']);

        self::assertSame([
            'php.method' => ['src/Keys.php:4'],
            'php.plain' => ['src/Keys.php:3'],
            'twig.block' => ['templates/page.twig:3'],
            'twig.double' => ['templates/page.twig:2'],
            'twig.single' => ['templates/page.twig:1'],
        ], $result->occurrences, 'klucz bez miejsca występowania jest listą napisów, której nie da się zweryfikować');
        self::assertSame(2, $result->filesScanned());
    }

    public function testTheSameKeyInTwoPlacesKeepsBothLocations(): void
    {
        $this->write('src/One.php', "<?php\n\ntrans('shared.key');\n");
        $this->write('templates/two.twig', "{{ 'shared.key'|trans }}\n");

        $result = $this->scanner->scan($this->root, ['src', 'templates']);

        self::assertSame(['shared.key'], $result->keys());
        self::assertSame(['src/One.php:3', 'templates/two.twig:1'], $result->where('shared.key'));
        self::assertSame([], $result->where('nie.ma.takiego'), 'nieznany klucz to pusta lista, a nie wyjątek');
    }

    public function testKeysAreReturnedSorted(): void
    {
        // Kolejność w pliku jest dowolna — `ScanResult::keys()` ma dawać listę posortowaną,
        // bo inaczej każdy push w CI zamieniałby się w diff, w którym zmieniło się wszystko.
        $this->write('src/Sorted.php', implode("\n", [
            '<?php',
            '',
            "trans('z.last');",
            "trans('a.first');",
            "trans('m.middle');",
        ]) . "\n");

        self::assertSame(['a.first', 'm.middle', 'z.last'], $this->scanner->scan($this->root, ['src'])->keys());
    }

    public function testDirectoriesThatAreNeverPartOfTheProjectAreSkipped(): void
    {
        $this->write('vendor/lib/Keys.php', "<?php\n\ntrans('vendor.key');\n");
        $this->write('node_modules/pkg/keys.twig', "{{ 'node_modules.key'|trans }}\n");
        $this->write('var/cache/Keys.php', "<?php\n\ntrans('var.key');\n");
        $this->write('cache/Keys.php', "<?php\n\ntrans('cache.key');\n");
        $this->write('.git/hooks/Keys.php', "<?php\n\ntrans('git.key');\n");
        $this->write('notes.md', "trans('markdown.key');\n");
        $this->write('src/Ok.php', "<?php\n\ntrans('good.key');\n");

        $result = $this->scanner->scan($this->root, ['.']);

        self::assertSame(['good.key'], $result->keys());
        self::assertCount(1, $result->files, 'skanujemy tylko pliki .php i .twig poza katalogami pomijanymi');
        self::assertStringEndsWith('src/Ok.php', implode(', ', $result->files));
    }

    public function testADynamicKeyProducesNoEntry(): void
    {
        $this->write('src/Dynamic.php', implode("\n", [
            '<?php',
            '',
            "echo trans('a.' ~ \$x);",
            'echo trans($dynamic);',
            "echo \$translator->trans('b.' . \$suffix);",
        ]) . "\n");

        $this->write('templates/dynamic.twig', implode("\n", [
            "{{ 'c.' ~ suffix|trans }}",
            '{% trans %}{{ dynamic }}{% endtrans %}',
        ]) . "\n");

        $result = $this->scanner->scan($this->root, ['src', 'templates']);

        self::assertSame(0, $result->count(), 'zgadywanie klucza składanego wprowadziłoby do serwisu puste wpisy');
        self::assertSame([], $result->occurrences);
        self::assertSame([], $result->keys());
    }

    public function testAMissingConfiguredDirectoryThrowsInsteadOfReportingZeroKeys(): void
    {
        // Literówka w `scan.paths` nie może cicho dać „0 kluczy” i skończyć się pushem pustej partii.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/„src”/');

        $this->scanner->scan($this->root, ['src']);
    }

    public function testScanningTheWholeTreeWithOnlySkippedDirectoriesYieldsZeroKeys(): void
    {
        $this->write('vendor/lib/Keys.php', "<?php\n\ntrans('vendor.key');\n");
        $this->write('node_modules/pkg/keys.twig', "{{ 'node_modules.key'|trans }}\n");

        $result = $this->scanner->scan($this->root, ['.']);

        self::assertSame(0, $result->count());
        self::assertSame(0, $result->filesScanned(), 'katalog bez własnego kodu to zero plików, a nie błąd');
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
