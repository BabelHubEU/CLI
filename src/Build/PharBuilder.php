<?php

declare(strict_types=1);

namespace BabelHub\Cli\Build;

use FilesystemIterator;
use Phar;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Buduje `babelhub.phar` — jedną binarkę, którą da się pobrać bez Composera (Fala 1, pozycja 1.11).
 *
 * **Po co PHAR, skoro jest `composer global require`.** `composer global require` jest wygodne
 * dla dewelopera PHP, ale CLI ma być kanałem akwizycji także dla kogoś, kto Composera nie ma
 * (albo ma go pod klucz i nie chce dokładać pakietu globalnego do obrazu CI). PHAR to jeden plik:
 * `curl -O …/babelhub.phar && chmod +x babelhub.phar` i działa.
 *
 * **Cztery decyzje, każda z powodem.**
 *
 * 1. **Bundlujemy tylko zależności CLI, nie cały vendor projektu.** CLI potrzebuje
 *    `symfony/console`, `symfony/yaml` i ich przechodnich zależności (10 pakietów); serwerowe
 *    `doctrine/*`, `slim/*`, `monolog/*` w binarni klienta byłyby balastem mierzonym w megabajtach
 *    — a ktoś, kto pobiera CLI, nie pobiera serwisu.
 * 2. **Autoloader generujemy do środka PHAR-a.** `vendor/autoload.php` projektu zawiera
 *    **absolutne ścieżki** do katalogu, w którym powstał (a nie do archiwum), więc binarka
 *    działałaby wyłącznie na maszynie autora. Mapy (classmap + PSR-4) są filtrowane do
 *    dołączonych pakietów i przepisywane na ścieżki `phar://`.
 * 3. **Ten sam `bin/babelhub`, bez zmian.** Skrypt szuka autoloadera po ścieżce
 *    `__DIR__ . '/../vendor/autoload.php'`, a wewnątrz PHAR-a `__DIR__` to `phar://…/bin` — więc
 *    wystarczy położyć wygenerowany autoloader pod `vendor/autoload.php` w archiwum. Jedno źródło
 *    wejścia dla trzech światów (repozytorium, pakiet Composera, PHAR).
 * 4. **Brak kompresji.** `compressFiles()` wymaga `zlib` w **środowisku uruchomienia**, więc
 *    binarka „działa u mnie” przestawałaby działać na minimalnym obrazie CI. Kilka megabajtów
 *    to tańsza cena niż błąd zależny od rozszerzenia PHP.
 *
 * Uruchamiane z `build-phar.php` w procesie z `-d phar.readonly=0` (PHP domyślnie nie pozwala
 * zapisywać archiwów — i to jest zabezpieczenie, a nie przeszkoda: budowanie ma być świadomym
 * krokiem, nie skutkiem ubocznym).
 */
final readonly class PharBuilder
{
    /** Nazwa archiwum w środku — ta sama, którą wskazuje `Phar::mapPhar()` w stubie. */
    public const ARCHIVE_NAME = 'babelhub.phar';

    /** Czego nie kopiujemy z pakietów: testy i dokumentacja to nie kod binarki. */
    private const SKIPPED = ['/Tests/', '/tests/', '/docs/', '/doc/'];

    public function __construct(
        private string $root,
        private string $output,
    ) {
    }

    public function build(): void
    {
        if (!$this->writable()) {
            throw new RuntimeException(
                'PHP ma włączone `phar.readonly`, więc nie mogę zapisać archiwum. ' .
                'Uruchom: php -d phar.readonly=0 build-phar.php',
            );
        }

        if (is_file($this->output)) {
            // `Phar` nie nadpisuje istniejącego archiwum — bez tego drugi build kończyłby się
            // błędem „phar already exists”, czyli binarka byłaby budowana raz na maszynę.
            unlink($this->output);
        }

        $packages = $this->dependencyClosure();

        $phar = new Phar($this->output, 0, self::ARCHIVE_NAME);
        $phar->startBuffering();

        $this->addTree($phar, $this->root . '/src', 'src');
        $phar->addFile($this->root . '/bin/babelhub', 'bin/babelhub');

        foreach ($packages as $package) {
            $this->addTree($phar, $this->root . '/vendor/' . $package, 'vendor/' . $package);
        }

        $this->addAutoloader($phar, $packages);

        $phar->setStub($this->stub());
        $phar->setSignatureAlgorithm(Phar::SHA256);
        $phar->stopBuffering();
    }

    /**
     * Nazwy pakietów, których CLI naprawdę potrzebuje: `require` z `composer.json` domknięte
     * po `require` z `vendor/composer/installed.json`.
     *
     * @return list<string>
     */
    public function dependencyClosure(): array
    {
        $manifest = $this->json($this->root . '/composer.json');
        /** @var array<string, mixed> $requires */
        $requires = is_array($manifest['require'] ?? null) ? $manifest['require'] : [];

        /** @var array<string, array<string, mixed>> $installed */
        $installed = [];
        foreach ($this->installedPackages() as $package) {
            $name = $package['name'] ?? null;
            if (is_string($name)) {
                $installed[$name] = $package;
            }
        }

        $queue = [];
        foreach (array_keys($requires) as $name) {
            if (!$this->isExtensionRequirement($name)) {
                $queue[] = $name;
            }
        }

        $closure = [];
        while ($queue !== []) {
            $name = (string) array_pop($queue);
            if (isset($closure[$name]) || !isset($installed[$name])) {
                continue;
            }

            $closure[$name] = true;

            /** @var array<string, mixed> $packageRequires */
            $packageRequires = is_array($installed[$name]['require'] ?? null) ? $installed[$name]['require'] : [];
            foreach (array_keys($packageRequires) as $dependency) {
                if (!$this->isExtensionRequirement($dependency)) {
                    $queue[] = $dependency;
                }
            }
        }

        $names = array_keys($closure);
        sort($names);

        if ($names === []) {
            throw new RuntimeException('Nie znalazłem żadnej zależności CLI — sprawdź composer.json i vendor/.');
        }

        foreach ($names as $name) {
            if (!is_dir($this->root . '/vendor/' . $name)) {
                throw new RuntimeException(sprintf('Brak katalogu vendor/%s — uruchom `composer install`.', $name));
            }
        }

        return $names;
    }

    private function writable(): bool
    {
        // `phar.readonly` jest domyślnie włączone i przychodzi jako `'1'` (bywa `On`/`true`
        // w zależności od SAPI), więc pytamy o **znaczenie**, a nie o napis.
        $value = strtolower(trim((string) ini_get('phar.readonly')));

        return !in_array($value, ['1', 'on', 'true', 'yes'], true);
    }

    private function isExtensionRequirement(string $name): bool
    {
        return str_starts_with($name, 'ext-') || $name === 'php' || str_starts_with($name, 'lib-');
    }

    private function addTree(Phar $phar, string $directory, string $prefix): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $base = rtrim(str_replace('\\', '/', $directory), '/');
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());
            foreach (self::SKIPPED as $skipped) {
                if (str_contains($path, $skipped)) {
                    continue 2;
                }
            }

            $phar->addFile($path, $prefix . '/' . substr($path, strlen($base) + 1));
        }
    }

    /**
     * Autoloader w środku archiwum: classmap i PSR-4 **przefiltrowane do dołączonych pakietów**
     * i przepisane na ścieżki `phar://`.
     *
     * @param list<string> $packages
     */
    private function addAutoloader(Phar $phar, array $packages): void
    {
        // **Klucze `$allowed` muszą być w tym samym kształcie co porównywane ścieżki** — czyli
        // z ukośnikami w przód. Na Linuksie `$this->root` backslashy nie ma, więc pomyłka była
        // niewidoczna; na Windowsie surowy `$this->root` w kluczach nie pasował do znormalizowanej
        // ścieżki z `autoload_psr4.php`, więc **mapy vendorowych pakietów wypadały z autoloadera
        // w binarni** — PHAR zbudowany na Windowsie nie znał `symfony/console` i kończył się
        // `Class "Symfony\Component\Console\Output\ConsoleOutput" not found` przy pierwszym
        // uruchomieniu. Wyszło dopiero z uruchomienia bramki na hoście z Windows; CI (Linux)
        // tego nie widzi, bo tam separator jest jeden.
        $root = rtrim(str_replace('\\', '/', $this->root), '/');
        $allowed = [$root . '/src' => 'src'];
        foreach ($packages as $package) {
            $allowed[$root . '/vendor/' . $package] = 'vendor/' . $package;
        }

        /** @var array<string, list<string>> $psr4 */
        $psr4 = require $this->root . '/vendor/composer/autoload_psr4.php';
        $directories = [];
        foreach ($psr4 as $prefix => $paths) {
            foreach ($paths as $path) {
                $relative = $this->relativeDirectory($path, $allowed);
                if ($relative !== null) {
                    $directories[$prefix][] = $relative;
                }
            }
        }

        /** @var array<string, string> $classmap */
        $classmap = require $this->root . '/vendor/composer/autoload_classmap.php';
        $classes = [];
        foreach ($classmap as $class => $file) {
            $relative = $this->relativeFile($file, $allowed);
            if ($relative !== null) {
                $classes[$class] = $relative;
            }
        }

        $phar->addFromString(
            'vendor/autoload.php',
            $this->autoloaderSource($directories, $classes, $this->autoloadFiles($packages)),
        );
    }

    /**
     * Pliki dołączane **bez klasy** — `autoload.files` z Composera.
     *
     * Bez tego binarka jest zepsuta na każdej maszynie bez `ext-intl` (i bez `mbstring`/`ctype`):
     * `symfony/string` woła `normalizer_is_normalized()`, a tę funkcję dostarcza
     * `symfony/polyfill-intl-normalizer` **właśnie przez `autoload.files`** (`bootstrap.php`),
     * nie przez klasę. Wygenerowany autoloader w archiwum znał tylko PSR-4 i classmapę, więc
     * funkcji nie było i `validate --check=hardcoded-strings` kończył się
     * `Call to undefined function Symfony\Component\String\normalizer_is_normalized()`.
     *
     * Wyszło dopiero z uruchomienia **joba pipeline'u** w obrazie `composer:2` (bez `intl`) —
     * na hoście i w kontenerze deweloperskim `intl` jest włączone, więc test binarki był zielony
     * i wada czekała na pierwszą osobę z „gołym” PHP, czyli dokładnie na użytkownika, który
     * pobiera PHAR-a, bo nie ma Composera.
     *
     * @param list<string> $packages
     *
     * @return list<string> ścieżki **względne wobec archiwum** (`vendor/…` albo `src/…`)
     */
    private function autoloadFiles(array $packages): array
    {
        $installed = [];
        foreach ($this->installedPackages() as $package) {
            $name = $package['name'] ?? null;
            if (is_string($name)) {
                $installed[$name] = $package;
            }
        }

        $files = [];

        // Pakiet główny: jego `autoload.files` są względne wobec korzenia repozytorium, a do
        // archiwum wchodzi tylko `src/` — plik spoza niego nie istnieje w binarni, więc go pomijamy.
        foreach ($this->rootAutoloadFiles() as $file) {
            if (str_starts_with($file, 'src/') && is_file($this->root . '/' . $file)) {
                $files[] = $file;
            }
        }

        foreach ($packages as $package) {
            /** @var mixed $declared */
            $declared = $installed[$package]['autoload']['files'] ?? null;
            if (!is_array($declared)) {
                continue;
            }

            foreach ($declared as $file) {
                if (!is_string($file)) {
                    continue;
                }

                $relative = 'vendor/' . $package . '/' . ltrim($file, '/');
                if (is_file($this->root . '/' . $relative)) {
                    $files[] = $relative;
                }
            }
        }

        sort($files);

        return $files;
    }

    /** @return list<string> */
    private function rootAutoloadFiles(): array
    {
        $manifest = $this->json($this->root . '/composer.json');
        /** @var mixed $files */
        $files = $manifest['autoload']['files'] ?? null;

        if (!is_array($files)) {
            return [];
        }

        $names = [];
        foreach ($files as $file) {
            if (is_string($file)) {
                $names[] = $file;
            }
        }

        return $names;
    }

    /**
     * @param array<string, list<string>> $psr4
     * @param array<string, string> $classmap
     * @param list<string> $files
     */
    private function autoloaderSource(array $psr4, array $classmap, array $files): string
    {
        $template = <<<'PHP'
        <?php

        // Wygenerowany przez `build-phar.php` (BabelHub CLI). Ścieżki są **względne wobec
        // archiwum**, żeby binarka działała na każdej maszynie, a nie tylko tam, gdzie powstała.
        //
        // **Adresujemy archiwum aliasem, nie nazwą pliku.** `Phar::running(false)` zwraca nazwę,
        // pod jaką plik leży u użytkownika — a tej nie kontrolujemy: przeglądarka potrafi zapisać
        // pobranie jako `babelhub(1).phar`, a człowiek nazwać je po swojemu. Wcześniej stało tu
        // `'phar://' . basename(Phar::running(false))`, co działało **wyłącznie** wtedy, gdy plik
        // nazywał się `babelhub.phar` (bo tylko taki alias rejestruje `Phar::mapPhar()` w stubie);
        // pod inną nazwą binarka kończyła się `phar error: invalid url or non-existent phar`.
        // Alias z `mapPhar()` działa niezależnie od nazwy pliku — i o to chodzi w pliku, który
        // ktoś pobiera, a nie buduje.
        $base = __BASE__;

        $classmap = __CLASSMAP__;
        $psr4 = __PSR4__;
        $files = __FILES__;

        spl_autoload_register(static function (string $class) use ($classmap, $psr4, $base): void {
            if (isset($classmap[$class])) {
                require $base . '/' . $classmap[$class];

                return;
            }

            foreach ($psr4 as $prefix => $directories) {
                if (!str_starts_with($class, $prefix)) {
                    continue;
                }

                $rest = str_replace('\\', '/', substr($class, strlen($prefix)));
                foreach ($directories as $directory) {
                    $file = $base . '/' . $directory . '/' . $rest . '.php';
                    if (is_file($file)) {
                        require $file;

                        return;
                    }
                }
            }
        });

        // Pliki `autoload.files` (polyfille funkcji) **po** rejestracji autoloadera — ta sama
        // kolejność co w autoloaderze Composera, żeby bootstrap mógł sięgnąć po klasę.
        foreach ($files as $file) {
            require $base . '/' . $file;
        }

        PHP;

        return str_replace(
            ['__CLASSMAP__', '__PSR4__', '__FILES__', '__BASE__'],
            [
                var_export($classmap, true),
                var_export($psr4, true),
                var_export($files, true),
                var_export('phar://' . self::ARCHIVE_NAME, true),
            ],
            $template,
        );
    }

    /**
     * Ścieżka katalogu wewnątrz archiwum albo `null`, gdy katalog nie należy do binarki.
     *
     * @param array<string, string> $allowed
     */
    private function relativeDirectory(string $directory, array $allowed): ?string
    {
        $normalized = rtrim(str_replace('\\', '/', $directory), '/');

        foreach ($allowed as $absolute => $relative) {
            if ($normalized === $absolute || str_starts_with($normalized, $absolute . '/')) {
                return $relative . substr($normalized, strlen($absolute));
            }
        }

        return null;
    }

    /** @param array<string, string> $allowed */
    private function relativeFile(string $file, array $allowed): ?string
    {
        $normalized = str_replace('\\', '/', $file);
        $directory = dirname($normalized);
        $relative = $this->relativeDirectory($directory, $allowed);

        return $relative === null ? null : $relative . '/' . basename($normalized);
    }

    private function stub(): string
    {
        $template = <<<'PHP'
        #!/usr/bin/env php
        <?php

        // Stub PHAR-a: mapuje archiwum (pod aliasem `__ARCHIVE__`) i uruchamia **ten sam**
        // `bin/babelhub`, który działa w repozytorium i po `composer global require`.
        //
        // Alias jest tu po to, żeby binarka działała pod **każdą** nazwą, jaką nada jej
        // użytkownik — i z tego samego aliasu korzysta wygenerowany autoloader w archiwum.
        Phar::mapPhar('__ARCHIVE__');

        require 'phar://__ARCHIVE__/bin/babelhub';

        __HALT_COMPILER();
        PHP;

        return str_replace('__ARCHIVE__', self::ARCHIVE_NAME, $template);
    }

    /** @return list<array<string, mixed>> */
    private function installedPackages(): array
    {
        $installed = $this->json($this->root . '/vendor/composer/installed.json');
        /** @var mixed $packages */
        $packages = $installed['packages'] ?? $installed;

        if (!is_array($packages)) {
            throw new RuntimeException('vendor/composer/installed.json ma nieznany kształt — uruchom `composer install`.');
        }

        /** @var list<array<string, mixed>> $list */
        $list = array_values(array_filter($packages, 'is_array'));

        return $list;
    }

    /** @return array<string, mixed> */
    private function json(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException(sprintf('Brak pliku %s — uruchom `composer install`.', $path));
        }

        /** @var mixed $decoded */
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('Plik %s nie jest obiektem JSON.', $path));
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
