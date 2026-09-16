<?php

declare(strict_types=1);

namespace BabelHub\Cli\Config;

use BabelHub\Cli\CliIoException;
use BabelHub\Cli\CliConfigException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Znajdowanie i zapis konfiguracji projektu — `.babelhub.json` albo `.babelhub.yml`.
 *
 * Plik szukamy **w górę drzewa katalogów**, bo developer uruchamia `babelhub push` z podkatalogu
 * (`src/`, `templates/`) równie często jak z korzenia — a narzędzie, które wymaga „stań dokładnie
 * w katalogu głównym”, jest narzędziem, o którym się zapomina. To ta sama zasada, co przy
 * `git`/`composer`: konfiguracja obowiązuje w całym drzewie poniżej.
 *
 * Dwa formaty, jedno miejsce (1.12): JSON był pierwszy, YAML dochodzi, bo tak konfigurują się
 * narzędzia tej kategorii i bo sekcje `upload`/`download`/`checks` czyta się w review jak
 * dokument. **Trzy decyzje, które trzeba znać:**
 *
 * 1. **Oba pliki naraz to błąd**, a nie „jeden wygrywa”. Dwa źródła prawdy o tym, co jedzie do
 *    serwisu, rozjeżdżają się przy pierwszej zmianie — a objawia się to nie komunikatem,
 *    tylko tym, że push wysyła nie to, co trzeba.
 * 2. **Szukamy w górę po obu nazwach**, więc katalog wyżej może mieć inny format niż bieżący —
 *    i to jest w porządku, dopóki w **jednym** katalogu jest tylko jeden plik.
 * 3. **Zapis jest atomowy** (plik tymczasowy + `rename`), bo `init` może nadpisać istniejący plik:
 *    przerwany zapis zostawiłby konfigurację w połowie, czyli w stanie, w którym żadne polecenie
 *    nie działa, a przyczyna nie jest widoczna.
 */
final class ProjectConfigStore
{
    public function __construct(private readonly string $startDirectory)
    {
    }

    /**
     * Katalog, w którym leży konfiguracja — albo `null`, gdy idąc w górę, nie ma jej nigdzie.
     *
     * @throws CliConfigException gdy w jednym katalogu są oba pliki
     */
    public function locate(): ?string
    {
        $directory = $this->startDirectory;

        while (true) {
            $found = $this->filesIn($directory);
            if ($found !== []) {
                return $directory;
            }

            $parent = dirname($directory);
            if ($parent === $directory) {
                return null;
            }

            $directory = $parent;
        }
    }

    /** Pełna ścieżka znalezionego pliku konfiguracji — `null`, gdy nie ma żadnego. */
    public function locateFile(): ?string
    {
        $directory = $this->locate();

        return $directory === null ? null : $directory . '/' . $this->filesIn($directory)[0];
    }

    public function load(): ?ProjectConfig
    {
        $path = $this->locateFile();
        if ($path === null) {
            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new CliConfigException(sprintf('Nie mogę odczytać %s.', $path));
        }

        $fileName = basename($path);

        return ProjectConfig::fromArray($this->decode($raw, $path, $fileName), $fileName);
    }

    /** Ścieżka pliku konfiguracji w katalogu, w którym ma powstać (bez szukania w górę). */
    public function pathIn(string $directory, string $fileName = ProjectConfig::FILE_NAME): string
    {
        return rtrim($directory, '/\\') . '/' . $fileName;
    }

    public function existsIn(string $directory): bool
    {
        return $this->filesIn($directory) !== [];
    }

    public function save(
        ProjectConfig $config,
        string $directory,
        string $fileName = ProjectConfig::FILE_NAME,
    ): string {
        // Katalog docelowy tworzymy **przed** zapisem, tak samo jak `LocaleFile::write()`.
        // Bez tego `init --directory=<nieistniejący>` kończył się ostrzeżeniem PHP
        // („Failed to open stream”) **przed** naszym wyjątkiem, czyli użytkownik widział
        // stos wywołań zamiast jednego zdania.
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new CliIoException(sprintf('Nie mogę utworzyć katalogu %s.', $directory));
        }

        $path = $this->pathIn($directory, $fileName);
        $contents = $fileName === ProjectConfig::FILE_NAME_YAML
            // `DUMP_EMPTY_ARRAY_AS_SEQUENCE`: pusta lista języków ma wyjść jako `[]`, a nie
            // `{  }`. Oba są poprawnym YAML-em, ale `{  }` czyta się jak „mapa”, a człowiek
            // zagląda do tego pliku właśnie po to, żeby zrozumieć, co jest ustawione.
            ? Yaml::dump($config->toArray(), 4, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE)
            : json_encode(
                $config->toArray(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );

        $temporary = $path . '.tmp';
        if (file_put_contents($temporary, $contents . "\n") === false) {
            throw new CliIoException(sprintf('Nie udało się zapisać %s.', $path));
        }

        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new CliIoException(sprintf('Nie udało się podmienić %s.', $path));
        }

        return $path;
    }

    /**
     * Nazwy plików konfiguracji obecne w katalogu — najwyżej jedna, inaczej błąd.
     *
     * @return list<string>
     */
    private function filesIn(string $directory): array
    {
        $found = [];
        foreach (ProjectConfig::FILE_NAMES as $fileName) {
            if (is_file($directory . '/' . $fileName)) {
                $found[] = $fileName;
            }
        }

        if (count($found) > 1) {
            throw new CliConfigException(sprintf(
                'W %s są oba pliki konfiguracji (%s). Zostaw jeden — dwa źródła prawdy o tym, ' .
                'co jedzie do serwisu, rozjadą się przy pierwszej zmianie.',
                $directory,
                implode(' i ', $found),
            ));
        }

        return $found;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws CliConfigException gdy plik się nie parsuje — ze **zdaniem parsera**, bo ono mówi,
     *   w której linii jest błąd, a nasze „niepoprawny plik” nie
     */
    private function decode(string $raw, string $path, string $fileName): array
    {
        if ($fileName === ProjectConfig::FILE_NAME_YAML) {
            try {
                $parsed = Yaml::parse($raw);
            } catch (ParseException $e) {
                throw new CliConfigException(sprintf('%s nie jest poprawnym YAML-em: %s', $path, $e->getMessage()));
            }

            if (!is_array($parsed)) {
                throw new CliConfigException(sprintf(
                    '%s nie jest mapą YAML (spodziewam się pól `project`, `source_locale`, `files`).',
                    $path,
                ));
            }

            /** @var array<string, mixed> $parsed */
            return $parsed;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new CliConfigException(sprintf(
                '%s nie jest poprawnym JSON-em. Popraw plik albo uruchom `babelhub init` ponownie.',
                $path,
            ));
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
