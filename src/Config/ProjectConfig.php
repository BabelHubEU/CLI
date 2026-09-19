<?php

declare(strict_types=1);

namespace BabelHub\Cli\Config;

use BabelHub\Cli\Checks\CheckName;
use BabelHub\Cli\CliConfigException;
use BabelHub\Cli\Files\LocaleWriteOptions;

/**
 * `.babelhub.json` — konfiguracja **projektu**, która leży w repozytorium i przechodzi code review.
 *
 * Czego tu **nie ma** i nie będzie: poświadczenia ani adresu serwera. To nie jest jedna decyzja,
 * a dwie:
 *
 * * **poświadczenie** żyje poza repozytorium (decyzja D4) — bo pierwszy odruch developera to
 *   wklejenie tokenu do pliku, który i tak jedzie do repo, a tak powstał znany wyciek w CLI
 *   konkurencji;
 * * **adres serwera** jest częścią poświadczenia, nie projektu: ten sam kod synchronizuje się
 *   ze stagingiem i z produkcją, więc adres należy do profilu poświadczenia
 *   (`--api-url` → `BABELHUB_API_URL` → plik poświadczeń). Repo opisuje **co** synchronizować,
 *   a nie **dokąd**.
 *
 * Walidacja jest **ścisła**: nieznany klucz to błąd, nie „przyszłościowe pole, które ktoś
 * dodał”. Plik, który leży w repozytorium i decyduje, co pojedzie do serwisu, nie może mieć
 * cicho ignorowanej literówki (`sourcelocale`, `sourceLocale`, `locale_source`) — objawiłaby
 * się dopiero tym, że push wysyła teksty w złym języku.
 */
final readonly class ProjectConfig
{
    public const FILE_NAME = '.babelhub.json';
    public const FILE_NAME_YAML = '.babelhub.yml';

    /**
     * Nazwy, pod którymi szukamy konfiguracji projektu — **w tej kolejności**.
     *
     * Dwa formaty, jedno miejsce: JSON był pierwszy (Fala 0/1), a YAML dochodzi w 1.12, bo tak
     * konfigurują się narzędzia tej kategorii (SimpleLocalize, Crowdin, Lokalise) i bo sekcje
     * `upload`/`download`/`checks` czyta się w review jak dokument, a nie jak dane.
     * **Oba naraz to błąd**, nie „jeden wygrywa”: dwa źródła prawdy o tym, co jedzie do serwisu,
     * rozjeżdżają się przy pierwszej zmianie, a objawia się to dopiero tym, że push wysyła
     * nie to, co trzeba.
     */
    public const FILE_NAMES = [self::FILE_NAME, self::FILE_NAME_YAML];

    public const FORMAT_PHP = 'php';
    public const FORMAT_JSON = 'json';

    /** Miejsca, w których domyślnie szukamy kluczy w kodzie. */
    public const DEFAULT_SCAN_PATHS = ['src', 'templates', 'config'];

    /** Domyślny próg bramki `validate` — ten sam, co w `--fail-on`. */
    public const DEFAULT_FAIL_ON = 'error';

    private const ALLOWED_ROOT_KEYS = [
        'project',
        'source_locale',
        'files',
        'scan',
        'upload',
        'download',
        'checks',
        '$schema',
    ];
    private const ALLOWED_FILES_KEYS = ['pattern', 'format', 'root_key'];
    private const ALLOWED_SCAN_KEYS = ['paths'];
    private const ALLOWED_UPLOAD_KEYS = ['mark_missing_as_removed', 'unapprove_changed'];
    private const ALLOWED_CHECKS_KEYS = ['fail_on', 'ignore', 'run'];
    private const ALLOWED_DOWNLOAD_KEYS = ['locales', 'sort', 'write_nested', 'post_process_command'];

    /**
     * @param list<string> $scanPaths
     * @param list<string> $downloadLocales języki do `pull` (puste = wszystkie języki projektu)
     * @param string $downloadSort kolejność kluczy w plikach (`file` albo `key`)
     * @param bool $downloadWriteNested czy pisać strukturę zagnieżdżoną w nowych plikach
     * @param string|null $downloadPostProcessCommand komenda uruchamiana po zapisaniu plików
     * @param string|null $fileRootKey klucz najwyższego poziomu, pod którym mieszkają komunikaty
     *        (`null` = komunikaty są całym dokumentem); patrz `LocaleWriteOptions`
     * @param list<CheckName> $checksRun kontrole uruchamiane przez `babelhub validate` bez `--check`
     * @param list<string> $checksIgnore ścieżki pomijane przez kontrolę `hardcoded-strings`
     */
    public function __construct(
        public string $project,
        public string $sourceLocale,
        public string $filePattern,
        public string $fileFormat,
        public array $scanPaths = self::DEFAULT_SCAN_PATHS,
        public bool $uploadMarkMissingAsRemoved = true,
        public bool $uploadUnapproveChanged = false,
        public array $downloadLocales = [],
        public string $downloadSort = LocaleWriteOptions::SORT_FILE,
        public bool $downloadWriteNested = false,
        public ?string $downloadPostProcessCommand = null,
        public string $checksFailOn = self::DEFAULT_FAIL_ON,
        public array $checksRun = [CheckName::Quality],
        public array $checksIgnore = [],
        public ?string $fileRootKey = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws CliConfigException gdy plik ma nieznany kształt — z listą tego, co wolno
     */
    public static function fromArray(array $data, string $fileName = self::FILE_NAME): self
    {
        self::rejectUnknownKeys($data, self::ALLOWED_ROOT_KEYS, 'korzenia', $fileName);

        $project = $data['project'] ?? null;
        if (!is_string($project) || trim($project) === '') {
            throw new CliConfigException(sprintf('Brak pola "project" (slug projektu w BabelHub) w %s.', $fileName));
        }

        $sourceLocale = $data['source_locale'] ?? null;
        if (!is_string($sourceLocale) || trim($sourceLocale) === '') {
            throw new CliConfigException(sprintf('Brak pola "source_locale" (np. "en_GB") w %s.', $fileName));
        }

        $files = $data['files'] ?? null;
        if (!is_array($files)) {
            throw new CliConfigException(sprintf('Brak sekcji "files" w %s (wzorzec ścieżki i format plików językowych).', $fileName));
        }

        self::rejectUnknownKeys($files, self::ALLOWED_FILES_KEYS, '"files"', $fileName);

        $pattern = $files['pattern'] ?? null;
        if (!is_string($pattern) || trim($pattern) === '') {
            throw new CliConfigException('Brak pola "files.pattern" (np. "translations/messages.{lang}.php").');
        }

        if (!str_contains($pattern, '{lang}')) {
            // Bez tego `pull` zapisałby wszystkie języki do jednego pliku i ostatni wygrał.
            throw new CliConfigException('Pole "files.pattern" musi zawierać placeholder "{lang}" — inaczej języki nadpisałyby się nawzajem.');
        }

        // Placeholdery są **zamkniętą listą**: `{lang}` (język) i `{ns}` (przestrzeń nazw klucza,
        // czyli pierwszy segment przed kropką). Literówka (`{lange}`, `{lang_code}`) zostałaby
        // inaczej wpisana do nazwy pliku dosłownie, a `pull` zapisałby wszystko do pliku
        // o nazwie z klamrami — i nikt nie zauważyłby tego aż do commita.
        preg_match_all('/\{([a-z_]+)\}/', $pattern, $placeholders);
        /** @var list<string> $found */
        $found = array_values(array_unique($placeholders[1]));
        $unknown = array_values(array_diff($found, ['lang', 'ns']));
        if ($unknown !== []) {
            throw new CliConfigException(sprintf(
                'Nieznany placeholder w "files.pattern": %s. Dozwolone: {lang}, {ns}.',
                implode(', ', array_map(static fn (string $name): string => '{' . $name . '}', $unknown)),
            ));
        }

        $format = $files['format'] ?? self::FORMAT_PHP;
        if (!is_string($format) || !in_array($format, [self::FORMAT_PHP, self::FORMAT_JSON], true)) {
            throw new CliConfigException(sprintf(
                'Pole "files.format" musi być jednym z: %s, %s.',
                self::FORMAT_PHP,
                self::FORMAT_JSON,
            ));
        }

        // `root_key` to **jeden klucz najwyższego poziomu**, nie ścieżka: kropka znaczyłaby
        // zagnieżdżenie (a koperta jest z definicji na wierzchu), a `{lang}` byłoby placeholderem,
        // którego tu nie podstawiamy. Literówka w tym polu jest cicha i droga — `push` wysłałby
        // klucze z prefiksem, a `pull` dołożyłby drugą kopię katalogu obok starej — więc
        // akceptujemy wyłącznie kształt, który da się odczytać bez zgadywania.
        $rootKey = $files['root_key'] ?? null;
        if ($rootKey !== null && (!is_string($rootKey) || preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/', $rootKey) !== 1)) {
            throw new CliConfigException(
                'Pole "files.root_key" musi być nazwą klucza najwyższego poziomu (np. "translations") albo null. '
                . 'Kropka, spacja i placeholder nie mają tu sensu: komunikaty mieszkają pod jednym kluczem.',
            );
        }

        $scan = $data['scan'] ?? [];
        if (!is_array($scan)) {
            throw new CliConfigException('Sekcja "scan" musi być obiektem z opcjonalnym polem "paths".');
        }

        self::rejectUnknownKeys($scan, self::ALLOWED_SCAN_KEYS, '"scan"', $fileName);

        $paths = $scan['paths'] ?? self::DEFAULT_SCAN_PATHS;
        if (!is_array($paths) || !array_is_list($paths)) {
            throw new CliConfigException('Pole "scan.paths" musi być listą katalogów (np. ["src", "templates"]).');
        }

        $normalized = [];
        foreach ($paths as $path) {
            if (!is_string($path) || trim($path) === '') {
                throw new CliConfigException('Każda ścieżka w "scan.paths" musi być niepustym napisem.');
            }
            $normalized[] = trim($path);
        }

        return new self(
            project: trim($project),
            sourceLocale: trim($sourceLocale),
            filePattern: trim($pattern),
            fileFormat: $format,
            scanPaths: $normalized === [] ? self::DEFAULT_SCAN_PATHS : $normalized,
            uploadMarkMissingAsRemoved: self::boolSection($data, 'upload', self::ALLOWED_UPLOAD_KEYS, 'mark_missing_as_removed', true, $fileName),
            uploadUnapproveChanged: self::boolSection($data, 'upload', self::ALLOWED_UPLOAD_KEYS, 'unapprove_changed', false, $fileName),
            downloadLocales: self::localeList($data, $fileName),
            downloadSort: self::downloadSort($data, $fileName),
            downloadWriteNested: self::boolSection($data, 'download', self::ALLOWED_DOWNLOAD_KEYS, 'write_nested', false, $fileName),
            downloadPostProcessCommand: self::postProcess($data, $fileName),
            checksFailOn: self::failOn($data, $fileName),
            checksRun: self::checksRun($data, $fileName),
            checksIgnore: self::checksIgnore($data, $fileName),
            fileRootKey: is_string($rootKey) ? $rootKey : null,
        );
    }

    /**
     * `checks.run` — **zestaw kontroli**, które uruchamia `babelhub validate` bez `--check`.
     *
     * Po co to w pliku, a nie w krokach CI: repo wie, czym jest jego bramka, a CI bywa cztery
     * razy inne (GitHub, GitLab, lokalny hook, ręczne uruchomienie). Deklaracja w repozytorium
     * znaczy, że „sprawdź projekt” robi wszędzie to samo, a nowa osoba w zespole nie musi
     * wiedzieć, których flag użyć.
     *
     * **Pusta lista jest błędem, nie „domyślnym zestawem”.** `checks.run: []` to bramka, która
     * zawsze przechodzi — a taka jest gorsza niż jej brak, bo wygląda na sprawdzenie. Kto chce
     * domyślnego zestawu, usuwa pole (albo wpisuje `[quality]`).
     *
     * @param array<string, mixed> $data
     *
     * @return list<CheckName>
     */
    private static function checksRun(array $data, string $fileName): array
    {
        $section = $data['checks'] ?? [];
        if (!is_array($section)) {
            throw new CliConfigException('Sekcja "checks" musi być obiektem.');
        }

        self::rejectUnknownKeys($section, self::ALLOWED_CHECKS_KEYS, '"checks"', $fileName);

        $run = $section['run'] ?? [CheckName::Quality->value];
        if (!is_array($run) || !array_is_list($run) || $run === []) {
            throw new CliConfigException(sprintf(
                'Pole "checks.run" w %s musi być niepustą listą kontroli (%s). Pusta lista to bramka, ' .
                'która zawsze przechodzi — usuń pole, żeby użyć domyślnej (%s).',
                $fileName,
                implode(', ', CheckName::names()),
                CheckName::Quality->value,
            ));
        }

        $checks = [];
        foreach ($run as $name) {
            // Nazwy są **zamkniętą listą** (`CheckName`): literówka w repozytorium ma kończyć się
            // kodem 2 z listą tego, co wolno wpisać — a nie cichym pominięciem kontroli,
            // o którym dowiemy się z nieudanego wdrożenia.
            $check = is_string($name) ? CheckName::tryFrom(trim($name)) : null;
            if ($check === null) {
                throw new CliConfigException(sprintf(
                    'Nieznana kontrola w "checks.run": %s. Dozwolone: %s.',
                    is_string($name) ? $name : get_debug_type($name),
                    implode(', ', CheckName::names()),
                ));
            }

            if (in_array($check, $checks, true)) {
                // Powtórzona kontrola to drugie żądanie i druga porcja tych samych ustaleń
                // w jednym uruchomieniu — czyli dokładnie ten szum, przez który ludzie
                // przestają czytać log bramki.
                throw new CliConfigException(sprintf('Kontrola "%s" powtórzona w "checks.run".', $check->value));
            }

            $checks[] = $check;
        }

        return $checks;
    }

    /**
     * `checks.ignore` — ścieżki, w których kontrola `hardcoded-strings` **nie** szuka.
     *
     * Potrzebne, bo heurystyka zawsze ma gdzieś rację, której człowiek nie chce naprawiać:
     * kod generowany, katalog migawek, stary moduł czekający na przepisanie. Wyjątek musi być
     * **jawny w repozytorium i widoczny w review** — inaczej ludzie wyłączają kontrolę na całe
     * repozytorium, a wtedy nie ma jej wcale.
     *
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private static function checksIgnore(array $data, string $fileName): array
    {
        $section = $data['checks'] ?? [];
        if (!is_array($section)) {
            throw new CliConfigException('Sekcja "checks" musi być obiektem.');
        }

        self::rejectUnknownKeys($section, self::ALLOWED_CHECKS_KEYS, '"checks"', $fileName);

        $ignore = $section['ignore'] ?? [];
        if (!is_array($ignore) || !array_is_list($ignore)) {
            throw new CliConfigException('Pole "checks.ignore" musi być listą ścieżek (np. ["src/Legacy"]).');
        }

        $normalized = [];
        foreach ($ignore as $path) {
            if (!is_string($path) || trim($path) === '') {
                throw new CliConfigException('Każda ścieżka w "checks.ignore" musi być niepustym napisem.');
            }

            $normalized[] = trim($path, '/');
        }

        return array_values(array_unique($normalized));
    }

    /**
     * `upload.mark_missing_as_removed` — czy `push` oznacza nieobecne klucze jako usunięte.
     *
     * Domyślnie `true` (tak działa flaga `--keep-missing`), a flaga **wygrywa z plikiem**: plik
     * mówi, jak zespół pracuje na co dzień, a flaga jest decyzją na jedno uruchomienie.
     *
     * @param array<string, mixed> $data
     * @param list<string> $allowed
     */
    private static function boolSection(
        array $data,
        string $section,
        array $allowed,
        string $key,
        bool $default,
        string $fileName,
    ): bool {
        $value = $data[$section] ?? [];
        if (!is_array($value)) {
            throw new CliConfigException(sprintf('Sekcja "%s" musi być obiektem.', $section));
        }

        self::rejectUnknownKeys($value, $allowed, '"' . $section . '"', $fileName);

        $flag = $value[$key] ?? $default;
        if (!is_bool($flag)) {
            throw new CliConfigException(sprintf('Pole "%s.%s" musi być true albo false.', $section, $key));
        }

        return $flag;
    }

    /**
     * `download.locales` — które języki pobiera `pull`.
     *
     * Pusta lista znaczy „wszystkie języki projektu” (dotychczasowe zachowanie), a nie „żaden”:
     * projekt prowadzi języki po stronie serwisu i to one są źródłem prawdy. Sekcja istnieje po to,
     * żeby repozytorium z jedną wersją językową nie ściągało dwudziestu plików.
     *
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private static function localeList(array $data, string $fileName): array
    {
        $section = $data['download'] ?? [];
        if (!is_array($section)) {
            throw new CliConfigException('Sekcja "download" musi być obiektem.');
        }

        self::rejectUnknownKeys($section, self::ALLOWED_DOWNLOAD_KEYS, '"download"', $fileName);

        $locales = $section['locales'] ?? [];
        if (!is_array($locales) || !array_is_list($locales)) {
            throw new CliConfigException('Pole "download.locales" musi być listą kodów języków (np. ["pl_PL"]).');
        }

        $normalized = [];
        foreach ($locales as $locale) {
            if (!is_string($locale) || trim($locale) === '') {
                throw new CliConfigException('Każdy język w "download.locales" musi być niepustym napisem.');
            }

            $normalized[] = trim($locale);
        }

        return array_values(array_unique($normalized));
    }

    /**
     * `download.sort` — kolejność kluczy w zapisywanych plikach (1.15/1.16b).
     *
     * Domyślnie `file`, czyli **kolejność z pliku**: istniejące klucze zostają na swoich miejscach,
     * a nowe dochodzą na końcu. Alfabetyczne sortowanie (`key`) jest decyzją zespołu i robi
     * jednorazowy diff porządkujący — dlatego jest opcją, a nie domyślnym zachowaniem: `pull`,
     * który przy każdym przebiegu tasuje cudzy plik, to dokładnie ten problem, od którego
     * odchodzimy (Unreal przepisujący pliki PO, konflikty w Weblate).
     *
     * @param array<string, mixed> $data
     */
    private static function downloadSort(array $data, string $fileName): string
    {
        $section = $data['download'] ?? [];
        if (!is_array($section)) {
            throw new CliConfigException('Sekcja "download" musi być obiektem.');
        }

        self::rejectUnknownKeys($section, self::ALLOWED_DOWNLOAD_KEYS, '"download"', $fileName);

        $sort = $section['sort'] ?? LocaleWriteOptions::SORT_FILE;
        if (!is_string($sort) || !in_array($sort, LocaleWriteOptions::SORTS, true)) {
            throw new CliConfigException(sprintf(
                'Pole "download.sort" w %s musi być jednym z: %s („file" = kolejność z pliku, „key" = alfabetyczna).',
                $fileName,
                implode(', ', LocaleWriteOptions::SORTS),
            ));
        }

        return $sort;
    }

    /**
     * `download.post_process_command` — komenda uruchamiana **po zapisaniu plików** (1.19).
     *
     * Po co to w konfiguracji, a nie w krokach CI: formatowanie plików językowych bywa sprawą
     * łańcucha budowania klienta (`prettier`, `php-cs-fixer`, własny skrypt sortujący), a `pull`
     * ma oddać plik gotowy do commita — nie taki, który ktoś musi jeszcze przetworzyć. Bez tego
     * hook-a każde CI musi pamiętać o drugim kroku, a ten, kto zapomni, commituje niesformatowany
     * plik i dowiaduje się o tym z różnicy w następnym PR-ze.
     *
     * **Komenda jest wykonywana powłoką w katalogu projektu**, dokładnie tak, jak zapisano ją
     * w repozytorium — to ta sama kategoria zaufania co `composer.json` (``scripts``), `Makefile`
     * czy `npm run`. Nic z danych (klucze, języki, odpowiedzi serwisu) nie jest do niej wstawiane:
     * podstawianie czegokolwiek zamieniłoby konfigurację w miejsce wstrzyknięcia kodu.
     *
     * Pusta wartość znaczy „brak hook-a”, a nie „uruchom pustą komendę”.
     *
     * @param array<string, mixed> $data
     */
    private static function postProcess(array $data, string $fileName): ?string
    {
        $section = $data['download'] ?? [];
        if (!is_array($section)) {
            throw new CliConfigException('Sekcja "download" musi być obiektem.');
        }

        self::rejectUnknownKeys($section, self::ALLOWED_DOWNLOAD_KEYS, '"download"', $fileName);

        $command = $section['post_process_command'] ?? null;
        if ($command === null) {
            return null;
        }

        if (!is_string($command)) {
            throw new CliConfigException(sprintf(
                'Pole "download.post_process_command" w %s musi być napisem z komendą (albo puste).',
                $fileName,
            ));
        }

        $command = trim($command);

        return $command === '' ? null : $command;
    }

    /**
     * `checks.fail_on` — domyślny próg bramki `validate` dla tego repozytorium.
     *
     * Flaga `--fail-on` wygrywa, więc zespół może zaostrzyć bramkę na jedno uruchomienie
     * (np. w nocy), nie zmieniając pliku w repozytorium i nie wymuszając tego na wszystkich.
     *
     * @param array<string, mixed> $data
     */
    private static function failOn(array $data, string $fileName): string
    {
        $section = $data['checks'] ?? [];
        if (!is_array($section)) {
            throw new CliConfigException('Sekcja "checks" musi być obiektem.');
        }

        self::rejectUnknownKeys($section, self::ALLOWED_CHECKS_KEYS, '"checks"', $fileName);

        $failOn = $section['fail_on'] ?? self::DEFAULT_FAIL_ON;
        if (!is_string($failOn) || !in_array($failOn, ['error', 'warning'], true)) {
            throw new CliConfigException(sprintf(
                'Pole "checks.fail_on" w %s musi być jednym z: error, warning.',
                $fileName,
            ));
        }

        return $failOn;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            // `$schema` pierwsze, bo edytory pokazują podpowiedzi w oparciu o nie (i nie
            // przeszkadza: to zwykłe pole, które walidacja zna).
            '$schema' => 'https://babelhub.dev/schema/project-v1.json',
            'project' => $this->project,
            'source_locale' => $this->sourceLocale,
            'files' => [
                'pattern' => $this->filePattern,
                'format' => $this->fileFormat,
                'root_key' => $this->fileRootKey,
            ],
            'scan' => [
                'paths' => $this->scanPaths,
            ],
            // Sekcje domyślne też zapisujemy: plik, który pokazuje **wszystkie** ustawienia
            // (także te, których nikt nie zmieniał), uczy, co jest do ustawienia — a usunięcie
            // linii przywraca dokładnie tę samą wartość, więc nic nie jest ukryte.
            'upload' => [
                'mark_missing_as_removed' => $this->uploadMarkMissingAsRemoved,
                'unapprove_changed' => $this->uploadUnapproveChanged,
            ],
            'download' => [
                'locales' => $this->downloadLocales,
                // Sekcje domyślne też zapisujemy, żeby plik uczył, co jest do ustawienia —
                // a usunięcie linii przywraca dokładnie tę samą wartość.
                'sort' => $this->downloadSort,
                'write_nested' => $this->downloadWriteNested,
                'post_process_command' => $this->downloadPostProcessCommand,
            ],
            'checks' => [
                'fail_on' => $this->checksFailOn,
                // Zestaw kontroli i wyjątki od kontroli lokalnej zapisujemy także wtedy, gdy są
                // domyślne: plik, który pokazuje **wszystkie** ustawienia, uczy, co jest
                // do ustawienia — a usunięcie linii przywraca dokładnie tę samą wartość.
                'run' => array_map(static fn (CheckName $check): string => $check->value, $this->checksRun),
                'ignore' => $this->checksIgnore,
            ],
        ];
    }

    /**
     * Ścieżka pliku językowego: `translations/messages.{lang}.php` + `pl_PL`.
     *
     * `{ns}` (przestrzeń nazw klucza) podstawiamy tylko wtedy, gdy wzorzec go zawiera — wtedy
     * `pull` zapisuje **osobny plik na przestrzeń nazw** (pierwszy segment klucza przed kropką),
     * tak jak robią to projekty z i18next. Wzorzec bez `{ns}` znaczy „wszystkie klucze projektu
     * w jednym pliku”, czyli dotychczasowe zachowanie.
     */
    public function fileFor(string $locale, ?string $root = null, ?string $namespace = null): string
    {
        $path = str_replace('{lang}', $locale, $this->filePattern);

        if ($namespace !== null) {
            $path = str_replace('{ns}', $namespace, $path);
        }

        return $root !== null ? rtrim($root, '/\\') . '/' . ltrim($path, '/\\') : $path;
    }

    /** Czy wzorzec rozdziela pliki na przestrzenie nazw (`{ns}` w `files.pattern`). */
    public function usesNamespaces(): bool
    {
        return str_contains($this->filePattern, '{ns}');
    }

    /** Opcje zapisu plików językowych — jedno miejsce, w którym konfiguracja staje się zachowaniem. */
    public function writeOptions(): LocaleWriteOptions
    {
        return new LocaleWriteOptions(
            nested: $this->downloadWriteNested,
            sort: $this->downloadSort,
            rootKey: $this->fileRootKey,
        );
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $allowed
     */
    private static function rejectUnknownKeys(array $data, array $allowed, string $where, string $fileName): void
    {
        $unknown = array_diff(array_keys($data), $allowed);
        if ($unknown !== []) {
            throw new CliConfigException(sprintf(
                'Nieznane pole w %s %s: %s. Dozwolone: %s.',
                $where,
                $fileName,
                implode(', ', $unknown),
                implode(', ', $allowed),
            ));
        }
    }
}
