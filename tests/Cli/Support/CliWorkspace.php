<?php

declare(strict_types=1);

namespace Tests\Cli\Support;

use BabelHub\Cli\Config\CatalogLock;
use BabelHub\Cli\Config\CatalogLockStore;
use BabelHub\Cli\Config\CredentialsStore;
use BabelHub\Cli\Config\ProjectConfig;
use BabelHub\Cli\Config\ProjectConfigStore;
use BabelHub\Cli\Console\Application;
use RuntimeException;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Katalog roboczy testu CLI: projekt na dysku, poświadczenie i uruchamianie komend.
 *
 * Cztery komendy (`pull`, `sync`, `status`, `validate`) potrzebują **tego samego otoczenia**:
 * katalogu tymczasowego na projekt, pliku konfiguracji, poświadczenia i wyjść w pamięci.
 * Powielone w czterech plikach rozjechałoby się przy pierwszej zmianie — dlatego jedno miejsce,
 * z którego każdy test bierze świeży katalog (i sprząta go w `tearDown`).
 *
 * Dwie decyzje warte nazwania:
 *
 * * **Poświadczenie jest zapisywane do pliku, a nie podawane flagą.** To realistyczna droga
 *   (parowanie w przeglądarce), a przy okazji sprawdza, że komenda faktycznie je rozwiązuje —
 *   flaga `--token` przeszłaby nawet wtedy, gdy odczyt pliku jest zepsuty.
 * * **`run()` przechodzi przez `Application::run()`**, czyli przez to samo wejście, które
 *   uruchamia człowiek: z `ArrayInput`, dwoma `BufferedOutput` i fabryką atrapy klienta.
 *   Dzięki temu sprawdzamy zachowanie komendy (kod wyjścia, strumienie, pliki), a nie jej
 *   wewnętrzne API.
 */
final class CliWorkspace
{
    public const API_URL = 'https://babelhub.example';
    public const TOKEN = 'pat_test.sekret';
    public const PROJECT = 'learnhub';
    public const CREDENTIALS = 'credentials.json';

    private readonly string $root;

    public function __construct()
    {
        $this->root = sys_get_temp_dir() . '/babelhub-cli-test-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
    }

    public function root(): string
    {
        return $this->root;
    }

    public function path(string $relativePath): string
    {
        return $this->root . '/' . $relativePath;
    }

    public function exists(string $relativePath): bool
    {
        return is_file($this->path($relativePath));
    }

    public function write(string $relativePath, string $contents): void
    {
        $path = $this->path($relativePath);
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Nie mogę utworzyć katalogu %s.', $directory));
        }

        file_put_contents($path, $contents);
    }

    /**
     * Treść pliku, który **musi** istnieć — brak pliku to niepusty napis w asercji, a błąd
     * z nazwą ścieżki: „CLI nic nie zapisało” i „zapisało coś innego” to dwie różne wiadomości.
     */
    public function read(string $relativePath): string
    {
        $path = $this->path($relativePath);
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException(sprintf('Nie ma pliku %s — test czyta coś, czego CLI nie zapisało.', $path));
        }

        return $contents;
    }

    public function remove(string $relativePath): void
    {
        $path = $this->path($relativePath);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /** @return list<string> nazwy plików w katalogu projektu (bez `.` i `..`) */
    public function entries(): array
    {
        $entries = scandir($this->root);
        if ($entries === false) {
            throw new RuntimeException(sprintf('Nie mogę odczytać katalogu %s.', $this->root));
        }

        return array_values(array_diff($entries, ['.', '..']));
    }

    /**
     * Czas modyfikacji pliku — **drugi, niezależny dowód**, że `pull` nic nie zapisał.
     *
     * Treść pliku można podmienić tak, żeby wyglądała na nietkniętą; `mtime` tego nie udaje.
     * `clearstatcache()` jest konieczne, bo PHP pamięta wynik `stat()` w obrębie żądania i bez
     * tego odczytalibyśmy wartość sprzed zapisu, czyli dokładnie to, co chcemy wykryć.
     */
    public function modificationTime(string $relativePath): int
    {
        $path = $this->path($relativePath);
        clearstatcache(true, $path);

        $time = filemtime($path);
        if ($time === false) {
            throw new RuntimeException(sprintf('Nie ma pliku %s.', $path));
        }

        return $time;
    }

    public function setModificationTime(string $relativePath, int $timestamp): void
    {
        touch($this->path($relativePath), $timestamp);
        clearstatcache(true, $this->path($relativePath));
    }

    /** `.babelhub.json` w katalogu projektu — konfiguracja czytana przez każdą komendę. */
    public function writeProjectConfig(
        string $project = self::PROJECT,
        string $sourceLocale = 'en_GB',
        string $format = ProjectConfig::FORMAT_PHP,
        string $pattern = 'translations/messages.{lang}.php',
    ): void {
        (new ProjectConfigStore($this->root))->save(new ProjectConfig(
            project: $project,
            sourceLocale: $sourceLocale,
            filePattern: $pattern,
            fileFormat: $format,
            scanPaths: ['src'],
        ), $this->root);
    }

    /**
     * `.babelhub.yml` w katalogu projektu — format dochodzi w 1.12 i niesie sekcje
     * `upload`/`download`/`checks`, których nie ma w JSON-ie zapisywanym przez `init`.
     *
     * @param list<string> $downloadLocales
     * @param list<string> $checksRun kontrole uruchamiane przez `validate` bez `--check`
     * @param list<string> $ignore ścieżki pomijane przez kontrolę `hardcoded-strings`
     */
    public function writeProjectConfigYaml(
        array $downloadLocales = [],
        bool $markMissingAsRemoved = true,
        string $failOn = 'error',
        array $checksRun = ['quality'],
        array $ignore = [],
    ): void {
        // **Przełączenie formatu, nie dołożenie pliku.** Oba naraz to błąd (`CliConfigException`),
        // więc test, który pisze YAML po `writeProjectConfig()`, mierzyłby ten błąd, a nie to,
        // co chciał sprawdzić.
        $this->remove(ProjectConfig::FILE_NAME);

        $locales = $downloadLocales === []
            ? '[]'
            : '[' . implode(', ', $downloadLocales) . ']';
        $checks = $checksRun === []
            ? '[]'
            : '[' . implode(', ', $checksRun) . ']';
        $ignoreYaml = $ignore === []
            ? 'ignore: []'
            : "ignore:\n" . implode("\n", array_map(static fn (string $p): string => '    - ' . $p, $ignore));

        $this->write(ProjectConfig::FILE_NAME_YAML, implode("\n", [
            'project: ' . self::PROJECT,
            'source_locale: en_GB',
            'files:',
            '  pattern: "translations/messages.{lang}.php"',
            '  format: php',
            'scan:',
            '  paths: [src]',
            'upload:',
            '  mark_missing_as_removed: ' . ($markMissingAsRemoved ? 'true' : 'false'),
            'download:',
            '  locales: ' . $locales,
            'checks:',
            '  fail_on: ' . $failOn,
            '  run: ' . $checks,
            '  ' . $ignoreYaml,
        ]) . "\n");
    }

    /** Poświadczenie poza repozytorium — dokładnie tak, jak zostawia je `babelhub login`. */
    public function writeCredentials(): void
    {
        $this->write(self::CREDENTIALS, (string) json_encode([
            'profiles' => [
                CredentialsStore::DEFAULT_PROFILE => [
                    'api_url' => self::API_URL,
                    'token' => self::TOKEN,
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    }

    public function loadLock(): ?CatalogLock
    {
        return (new CatalogLockStore($this->root))->load();
    }

    public function saveLock(CatalogLock $lock): void
    {
        (new CatalogLockStore($this->root))->save($lock);
    }

    /**
     * Uruchomienie komendy tak, jak z linii poleceń.
     *
     * `--directory` wskazuje katalog projektu, a fabryka klienta podstawia atrapę, więc test nie
     * wychodzi do sieci. Klucze opcji mają przedrostek `--`, bo `ArrayInput` rozróżnia argumenty
     * od opcji właśnie po nim — `'directory' => …` zostałoby potraktowane jako argument i Symfony
     * odpowiedziałoby „The "directory" argument does not exist” (kodem `1`, czyli wyglądając na
     * awarię narzędzia zamiast na błąd użycia).
     *
     * @param array<string, mixed> $arguments argumenty polecenia (z `command` włącznie)
     * @param callable(string, ?string, float): FakeApiClient|null $clientFactory własna fabryka,
     *   gdy test musi zobaczyć **z czym** komenda zbudowała klienta (np. `--timeout`)
     * @param bool $projectScoped czy dokładać `--directory`. Nie każda komenda ma tę opcję:
     *   `whoami`, `login` i `logout` nie dotyczą projektu, więc podanie im `--directory` kończy
     *   się błędem użycia z Symfony („The "--directory" option does not exist”) — czyli test
     *   mierzyłby własny artefakt, a nie zachowanie komendy
     *
     * @return array{code: int, stdout: string, stderr: string}
     */
    public function run(
        array $arguments,
        FakeApiClient $client,
        ?callable $clientFactory = null,
        bool $projectScoped = true,
    ): array {
        $stdout = new BufferedOutput();
        $stderr = new BufferedOutput();

        $code = Application::run(
            credentialsPath: $this->path(self::CREDENTIALS),
            stdout: $stdout,
            stderr: $stderr,
            input: new ArrayInput($projectScoped ? $arguments + ['--directory' => $this->root] : $arguments),
            clientFactory: $clientFactory
                ?? static fn (string $url, ?string $token, float $timeout = 15.0): FakeApiClient => $client,
        );

        return ['code' => $code, 'stdout' => $stdout->fetch(), 'stderr' => $stderr->fetch()];
    }

    /**
     * Uruchomienie z **prawdziwego wiersza poleceń** (`ArgvInput`), a nie z `ArrayInput`.
     *
     * Różnica nie jest kosmetyczna i wyszła dopiero na żywym CLI: `ArrayInput` przy nieznanej
     * opcji rzuca `InvalidArgumentException`, a `ArgvInput` — `Symfony\...\Exception\RuntimeException`.
     * Ten sam błąd człowieka dawał więc kod `2` w teście i `1` u użytkownika. Testy parsowania
     * flag muszą iść tędy.
     *
     * @param list<string> $argv argumenty **z nazwą programu** na początku, jak w `$_SERVER['argv']`
     *
     * @return array{code: int, stdout: string, stderr: string}
     */
    public function runArgv(array $argv, FakeApiClient $client): array
    {
        $stdout = new BufferedOutput();
        $stderr = new BufferedOutput();

        $code = Application::run(
            credentialsPath: $this->path(self::CREDENTIALS),
            stdout: $stdout,
            stderr: $stderr,
            input: new ArgvInput($argv),
            clientFactory: static fn (string $url, ?string $token, float $timeout = 15.0): FakeApiClient => $client,
        );

        return ['code' => $code, 'stdout' => $stdout->fetch(), 'stderr' => $stderr->fetch()];
    }

    /** Rekursywne sprzątanie — `failOnWarning` w `phpunit.xml` wywala przebieg za nieposprzątany katalog. */
    public function cleanup(): void
    {
        $this->removeDirectory($this->root);
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
