<?php

declare(strict_types=1);

namespace Tests\Cli;

use BabelHub\Cli\Version;
use Phar;
use PHPUnit\Framework\TestCase;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * `babelhub.phar` — binarka, którą da się pobrać bez Composera (Fala 1, pozycja 1.11).
 *
 * Test buduje **prawdziwe archiwum** (w osobnym procesie, bo PHP domyślnie nie pozwala zapisywać
 * PHAR-ów) i uruchamia je **jak użytkownik**: `php babelhub.phar …` z katalogu poza repozytorium.
 * To jest jedyny sposób, żeby sprawdzić rzeczy, których nie widzi żaden test in-process:
 * czy binarka nie odwołuje się do `vendor/` projektu (ścieżki absolutne w autoloaderze!),
 * czy nie wciągnęła kodu serwera i czy działa bez `composer install`.
 *
 * Trzy reguły, które ten test rozdziela:
 *
 * 1. **Binarka jest samodzielna** — zero odwołań do katalogu, w którym powstała, i zero pakietów
 *    serwerowych (`doctrine/*`, `slim/*`). Inaczej „pobrałem PHAR-a” znaczyłoby „pobrałem coś,
 *    co działa tylko u autora”.
 * 2. **Budowanie jest świadomym krokiem** — bez `-d phar.readonly=0` skrypt mówi wprost, czego
 *    brakuje, i kończy się kodem `1`, a nie „sukcesem” bez pliku.
 * 3. **Kontrola lokalna działa w binarni** — `validate --check=hardcoded-strings` nie potrzebuje
 *    serwera, więc jest najlepszym dowodem, że dołączone zależności (skaner PHP/Twig, parser YAML)
 *    naprawdę jadą w archiwum.
 */
final class PharBuildTest extends TestCase
{
    private const COMMANDS = [
        'login', 'logout', 'whoami', 'init', 'scan', 'push', 'pull', 'sync', 'status', 'diff', 'validate',
    ];

    private string $root;

    private string $phar;

    protected function setUp(): void
    {
        parent::setUp();

        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open jest wyłączone — nie zbuduję ani nie uruchomię binarki');
        }

        $this->root = sys_get_temp_dir() . '/babelhub-phar-test-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
        $this->phar = $this->root . '/babelhub.phar';
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);

        parent::tearDown();
    }

    public function testTheBuildProducesAStandaloneBinary(): void
    {
        $this->build();

        self::assertFileExists($this->phar);
        self::assertGreaterThan(
            500 * 1024,
            (int) filesize($this->phar),
            'binarka poniżej pół megabajta znaczy, że nie zabrała zależności (symfony/console, yaml)',
        );

        $contents = (string) file_get_contents($this->phar);
        self::assertStringNotContainsString(
            dirname(__DIR__, 2),
            $contents,
            'ścieżka projektu w archiwum = binarka działa tylko na maszynie, na której powstała',
        );

        $inside = $this->entries();
        self::assertContains('bin/babelhub', $inside);
        self::assertContains('vendor/autoload.php', $inside);
        self::assertContains('vendor/symfony/console/Application.php', $inside);

        foreach ($inside as $path) {
            self::assertStringNotContainsString('src/Modules/', $path, 'kod serwera nie jedzie w binarce klienta');
            self::assertStringNotContainsString('vendor/doctrine/', $path);
            self::assertStringNotContainsString('vendor/slim/', $path);
            self::assertStringNotContainsString('/Tests/', $path);
        }
    }

    public function testTheBinaryRunsOutsideTheRepository(): void
    {
        $this->build();

        // Katalog **poza** repozytorium: gdyby stub szukał `vendor/autoload.php` projektu,
        // poleciałby „Class not found” dokładnie tak, jak u użytkownika po `curl -O`.
        $outside = sys_get_temp_dir() . '/babelhub-phar-outside-' . bin2hex(random_bytes(6));
        mkdir($outside, 0777, true);

        try {
            $version = $this->process([PHP_BINARY, $this->phar, '--version'], $outside);

            self::assertSame(0, $version['code'], $version['stderr']);
            self::assertStringContainsString('BabelHub CLI ' . Version::VALUE, $version['stdout']);

            $list = $this->process([PHP_BINARY, $this->phar, 'list', '--format=json'], $outside);
            self::assertSame(0, $list['code'], $list['stderr']);

            /** @var array{commands: list<array{name: string}>} $document */
            $document = json_decode(trim($list['stdout']), true, 512, JSON_THROW_ON_ERROR);
            $names = array_column($document['commands'], 'name');

            foreach (self::COMMANDS as $command) {
                self::assertContains($command, $names, sprintf('binarka nie zna polecenia „%s”', $command));
            }
        } finally {
            $this->removeDirectory($outside);
        }
    }

    /**
     * Binarka działa pod **każdą** nazwą pliku — bo tak wygląda jej życie u użytkownika.
     *
     * Plik z wydania rzadko zachowuje nazwę, którą mu daliśmy: przeglądarka potrafi zapisać
     * pobranie jako `babelhub(1).phar`, a człowiek nazwać je po swojemu. Stub mapuje więc alias
     * (`Phar::mapPhar()`), a autoloader w archiwum adresuje się **tym aliasem** — wcześniej
     * adresował się `basename(Phar::running(false))`, czyli nazwą pliku, i pod inną nazwą
     * kończył się `phar error: invalid url or non-existent phar`.
     *
     * Ten test jest tu, bo poprzednie **nie mogły** tego złapać: wszystkie budowały plik o nazwie
     * `babelhub.phar`, czyli dokładnie tej, przy której wadliwe `basename()` przypadkiem działało.
     * Wada wyszła dopiero z pobrania opublikowanego assetu pod zmienioną nazwą.
     */
    public function testTheBinaryRunsUnderADifferentFileName(): void
    {
        $renamed = $this->root . '/babelhub-cli-pobrany(1).phar';
        $result = $this->buildProcess(withReadonlyOff: true, output: $renamed);

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertFileExists($renamed);

        $outside = sys_get_temp_dir() . '/babelhub-phar-renamed-' . bin2hex(random_bytes(6));
        mkdir($outside, 0777, true);

        try {
            $version = $this->process([PHP_BINARY, $renamed, '--version'], $outside);

            self::assertSame(0, $version['code'], $version['stderr']);
            self::assertStringContainsString('BabelHub CLI ' . Version::VALUE, $version['stdout']);

            $list = $this->process([PHP_BINARY, $renamed, 'list', '--format=json'], $outside);
            self::assertSame(0, $list['code'], $list['stderr']);
        } finally {
            $this->removeDirectory($outside);
        }
    }

    /**
     * Kontrola lokalna w binarni: skaner kodu i parser konfiguracji muszą działać **bez serwera**.
     * `--fail-on=warning` daje kod `6`, a domyślne `--fail-on=error` — `0`, bo heurystyka nie
     * blokuje merge'a (ta sama reguła co w repozytorium, patrz `AGENTS.md`).
     */
    public function testTheBinaryRunsTheLocalCheckWithoutTheService(): void
    {
        $this->build();

        $project = $this->root . '/project';
        mkdir($project . '/src', 0777, true);
        file_put_contents($project . '/src/Home.php', "<?php\n\ntrans('home.title');\nt('Zdarzenie po polsku');\n");
        file_put_contents($project . '/.babelhub.json', (string) json_encode([
            'project' => 'learnhub',
            'source_locale' => 'en_GB',
            'files' => ['pattern' => 'translations/messages.{lang}.php', 'format' => 'php'],
            'scan' => ['paths' => ['src']],
        ], JSON_THROW_ON_ERROR));

        $strict = $this->process(
            [PHP_BINARY, $this->phar, 'validate', '--check=hardcoded-strings', '--directory=' . $project, '--fail-on=warning'],
            $project,
        );

        self::assertSame(6, $strict['code'], $strict['stderr'] . $strict['stdout']);
        self::assertStringContainsString('src/Home.php', $strict['stdout']);

        $default = $this->process(
            [PHP_BINARY, $this->phar, 'validate', '--check=hardcoded-strings', '--directory=' . $project],
            $project,
        );

        self::assertSame(
            0,
            $default['code'],
            'heurystyka nie może blokować domyślnej bramki — inaczej uczy się ją obchodzić',
        );
    }

    public function testTheBuildRefusesToRunWithPharReadonly(): void
    {
        $result = $this->buildProcess(withReadonlyOff: false);

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('phar.readonly', $result['stderr']);
        self::assertFileDoesNotExist($this->phar, 'nieudany build nie może udawać sukcesu');
    }

    /**
     * Drugie budowanie nad istniejącym archiwum musi się udać.
     *
     * `Phar` **nie nadpisuje** istniejącego pliku („phar already exists”), więc bez świadomego
     * `unlink` binarka byłaby budowana raz na maszynę — a to jest dokładnie ten rodzaj awarii,
     * którego nie widzi pierwszy, „czysty” przebieg.
     */
    public function testTheBuilderRebuildsOverAnExistingArchive(): void
    {
        $this->build();
        $first = (int) filesize($this->phar);

        $again = $this->buildProcess(withReadonlyOff: true);

        self::assertSame(0, $again['code'], $again['stderr']);
        self::assertGreaterThan(0, $first);
        self::assertFileExists($this->phar);
    }

    private function build(): void
    {
        $result = $this->buildProcess(withReadonlyOff: true);

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertStringContainsString('Zbudowane:', $result['stdout']);
    }

    /** @return array{code: int, stdout: string, stderr: string} */
    private function buildProcess(bool $withReadonlyOff, ?string $output = null): array
    {
        $command = [PHP_BINARY];
        if ($withReadonlyOff) {
            $command[] = '-d';
            $command[] = 'phar.readonly=0';
        }

        $command[] = dirname(__DIR__, 2) . '/build-phar.php';
        $command[] = $output ?? $this->phar;

        return $this->process($command, $this->root);
    }

    /** @return list<string> */
    private function entries(): array
    {
        $phar = new Phar($this->phar);
        // `getPathname()` zwraca `phar://…` **ze zwykłymi ukośnikami** także na Windowsie, więc
        // prefiks trzeba znormalizować — inaczej żadna ścieżka nie pasuje i asercje o zawartość
        // archiwum padają na Windowsie mimo poprawnego archiwum (dokładnie tak wyszło:
        // „array contains 'bin/babelhub'” przy pliku, który w archiwum był).
        $prefix = 'phar://' . str_replace('\\', '/', $this->phar) . '/';
        $paths = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator($phar) as $file) {
            $paths[] = str_replace($prefix, '', str_replace('\\', '/', $file->getPathname()));
        }

        return $paths;
    }

    /**
     * @param list<string> $command
     *
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function process(array $command, string $directory): array
    {
        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $directory,
        );

        if (!is_resource($process)) {
            self::fail('nie udało się uruchomić: ' . implode(' ', $command));
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
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
