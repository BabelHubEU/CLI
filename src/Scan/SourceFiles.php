<?php

declare(strict_types=1);

namespace BabelHub\Cli\Scan;

use RuntimeException;

/**
 * Które pliki skanujemy i czego **nigdy** nie ruszamy — jedno miejsce dla obu skanerów.
 *
 * Wydzielone, gdy doszedł drugi skaner (`HardcodedStringsScanner`, pozycja 1.13), bo lista
 * pomijanych katalogów jest decyzją, a nie szczegółem: `vendor` i `node_modules` zawierają
 * tłumaczenia bibliotek (nie nasze), `var`/`cache` — wygenerowane pliki, a `.git` obiekty,
 * w których tekst wygląda jak tekst, ale niczyj. Dwa skanery z dwiema listami rozjechałyby się
 * przy pierwszej zmianie i dały wynik, którego nikt nie umie wyjaśnić: „skan kluczy widzi 240,
 * a kontrola stringów 3 000”.
 *
 * Rozszerzenia są **zamknięte** (`php`, `twig`) z tego samego powodu: to adapter tej technologii,
 * a nie próba obsłużenia wszystkich plików w repozytorium.
 */
final class SourceFiles
{
    private const SKIPPED_DIRECTORIES = [
        'vendor',
        'node_modules',
        'var',
        'cache',
        '.git',
        '.idea',
        'bower_components',
    ];

    private const EXTENSIONS = ['php', 'twig'];

    /**
     * @param string $root korzeń projektu
     * @param list<string> $paths katalogi względem korzenia
     *
     * @return list<string> ścieżki względem korzenia, bez wiodącego `./`
     *
     * @throws RuntimeException gdy któryś katalog nie istnieje — literówka w konfiguracji
     *                          nie może cicho dać „0 kluczy” ani „0 problemów”
     */
    public function in(string $root, array $paths): array
    {
        $found = [];

        foreach ($paths as $path) {
            $absolute = rtrim($root, '/\\') . '/' . ltrim($path, '/\\');
            if (!is_dir($absolute)) {
                throw new RuntimeException(sprintf(
                    'Katalog „%s” z konfiguracji nie istnieje (szukałem w %s).',
                    $path,
                    $absolute,
                ));
            }

            foreach ($this->walk($absolute, $root) as $file) {
                $found[] = $file;
            }
        }

        sort($found);

        return $found;
    }

    /** @return list<string> */
    private function walk(string $directory, string $root): array
    {
        $found = [];

        $entries = scandir($directory) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $absolute = $directory . '/' . $entry;
            if (is_dir($absolute)) {
                if (in_array($entry, self::SKIPPED_DIRECTORIES, true)) {
                    continue;
                }

                $found = [...$found, ...$this->walk($absolute, $root)];
                continue;
            }

            if (!preg_match('/\.(' . implode('|', self::EXTENSIONS) . ')$/', $entry)) {
                continue;
            }

            $found[] = self::relativePath($absolute, $root);
        }

        return $found;
    }

    /**
     * Ścieżka względem korzenia projektu, **bez wiodącego `./`**.
     *
     * Skanowanie `'.'` (domyślny fallback, gdy projekt nie ma `src`/`templates`/`config`) dawało
     * `./src/Foo.php:12`, a dokumentacja i `ScanResult` obiecują `src/Foo.php:12`. Różnica jest
     * kosmetyczna dla oka i **nie** jest kosmetyczna dla klienta: `init` zapisuje `scan.paths`,
     * więc ten sam projekt raz dawał jedne ścieżki, a raz drugie — zależnie od tego, czy
     * w katalogu głównym był `src`.
     */
    private static function relativePath(string $absolute, string $root): string
    {
        $relative = str_replace('\\', '/', substr($absolute, strlen(rtrim($root, '/\\'))));
        $relative = ltrim($relative, '/');

        while (str_starts_with($relative, './')) {
            $relative = substr($relative, 2);
        }

        return $relative;
    }
}
