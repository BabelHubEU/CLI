<?php

declare(strict_types=1);

namespace BabelHub\Cli\Files;

use BabelHub\Cli\CliIoException;
use RuntimeException;

/**
 * Plik językowy projektu: czytanie i zapis w formacie, który **już jest w repozytorium**.
 *
 * To jest miejsce, w którym rozstrzyga się pozycja 1.16b planu („`pull` zapisuje pliki
 * w formacie, w jakim je przeczytał”) — i powód, dla którego nie ma tu jednego „naszego”
 * formatu. Walka narzędzia z łańcuchem budowania klienta to najlepiej udokumentowany ból tej
 * kategorii: Unreal przepisuje pliki PO i każde scalanie kończy się konfliktem, a w Weblate
 * zgłoszenie o konfliktach jest otwarte od stycznia 2022. Nasza odpowiedź jest prosta:
 * **czytamy i piszemy to, co projekt ma**, a nie to, co nam wygodniej.
 *
 * Co dokładnie jest zachowywane przy zapisie (i skąd to wiemy — z pliku, nie z konfiguracji):
 * końce linii, końcowy znak nowej linii, wcięcia, struktura (płaska albo zagnieżdżona),
 * kolejność kluczy, `declare(strict_types=1)`, `array()` vs `[]` oraz **klucze, których katalog
 * nie zna** (plik nie jest naszą własnością — `pull` nie kasuje cudzej pracy). Decyzje zespołu
 * z konfiguracji (`sort`, `write_nested`) wygrywają z tym, co widać w pliku, bo inaczej nie
 * dałoby się zmienić formatu istniejącego projektu.
 *
 * **Jedno świadome odstępstwo: cudzysłowy w PHP.** Wartości idą przez `var_export`, więc zawsze
 * w apostrofach. Własny escaper byłby tańszy w diffie i droższy w skutkach: to w nim narzędzia
 * tej kategorii po cichu psują teksty z apostrofem („It's done”) i ze znakiem nowej linii —
 * a błąd widać dopiero w aplikacji, nie w podglądzie.
 *
 * **`include` przy czytaniu PHP jest świadomym zaufaniem do własnego repozytorium.** Tak trzyma
 * tłumaczenia cały ekosystem PHP (Laravel, Symfony), a plik jest kodem projektu, który i tak
 * wykonuje się przy każdym żądaniu aplikacji. Nie jest to „wykonywanie obcego kodu”: CLI
 * uruchamia się na maszynie developera, na jego własnym repozytorium.
 */
final readonly class LocaleFile
{
    public function __construct(
        private string $format,
    ) {
    }

    /**
     * Odczyt w postaci **płaskiej** (`nav.title` => tekst), także z pliku zagnieżdżonego.
     *
     * Spłaszczanie jest tu istotne z dwóch powodów: `push` potrzebuje tekstów źródłowych
     * w jednym słowniku niezależnie od tego, jak projekt trzyma je w pliku, a rozpoznanie
     * „ten plik jest zagnieżdżony” to jedyny sposób, żeby po `pull` nie zmienić jego struktury.
     *
     * @return array<string, string>
     *
     * @throws RuntimeException gdy plik jest nieczytelny albo nie zwraca tablicy `klucz => tekst`
     */
    public function read(string $path): array
    {
        return $this->parse($path)['messages'];
    }

    /**
     * Zapis **atomowy**: plik tymczasowy w tym samym katalogu + `rename`.
     *
     * `rename` w obrębie jednego systemu plików jest atomowy, więc czytelnik (aplikacja,
     * `git`, edytor) widzi albo starą treść, albo nową — nigdy połowę. Przy `pull` to nie
     * kosmetyka: przerwany zapis (Ctrl+C, brak miejsca) zostawiłby plik bez końca, a to jest
     * dokładnie ten rodzaj uszkodzenia, który trafia do commita, bo „plik się zmienił”.
     *
     * @param array<string, string> $messages
     */
    public function write(string $path, array $messages, LocaleWriteOptions $options = new LocaleWriteOptions()): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new CliIoException(sprintf('Nie mogę utworzyć katalogu %s.', $directory));
        }

        $style = $this->styleOf($path, $options);
        $merged = $this->merge($messages, $style, $options);
        $contents = $this->render($merged, $style, $options);

        $temporary = $path . '.tmp';
        if (file_put_contents($temporary, $contents) === false) {
            throw new CliIoException(sprintf('Nie udało się zapisać %s.', $temporary));
        }

        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new CliIoException(sprintf('Nie udało się podmienić %s.', $path));
        }
    }

    /**
     * Styl pliku, który leży w repozytorium — albo domyślny, gdy pliku nie ma.
     *
     * **Plik nieczytelny traktujemy jak nieistniejący** i to jest decyzja: `pull` ma naprawić
     * zepsuty plik, a nie odmówić pracy. Stylu i tak nie da się z niego odczytać (nie ma czego
     * parsować), a wtedy jedyne, co moglibyśmy zrobić, to zgłosić błąd — czyli `pull` przestaje
     * działać dokładnie w momencie, w którym jest najbardziej potrzebny. Plik dostaje nasz
     * domyślny format, bo „naprawa” znaczy tu: odtworzenie go z katalogu.
     */
    private function styleOf(string $path, LocaleWriteOptions $options): LocaleFileStyle
    {
        if (!is_file($path)) {
            return LocaleFileStyle::defaults($options->nested);
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            return LocaleFileStyle::defaults($options->nested);
        }

        try {
            $parsed = $this->parse($path);
        } catch (RuntimeException) {
            return LocaleFileStyle::defaults($options->nested);
        }

        return $this->styleOfRaw($raw, $parsed['messages'], $parsed['nested'], $parsed['order']);
    }

    /**
     * @param array<string, string> $values
     * @param list<string> $order
     */
    private function styleOfRaw(string $raw, array $values, bool $nested, array $order): LocaleFileStyle
    {
        return new LocaleFileStyle(
            eol: $this->detectEol($raw),
            finalNewline: str_ends_with($raw, "\n"),
            indent: $this->detectIndent($raw),
            nested: $nested,
            strictTypes: $this->format !== 'json' && str_contains($raw, 'strict_types'),
            longArraySyntax: $this->format !== 'json' && preg_match('/\breturn\s+array\s*\(/', $raw) === 1,
            order: $order,
            values: $values,
        );
    }

    /**
     * Końce linii: **większość wygrywa**, nie pierwsze wystąpienie.
     *
     * Plik z mieszanymi końcami istnieje naprawdę (edytor dopisał linię na Windows), a wtedy
     * pierwsza linia nie mówi nic o reszcie i przepisanie całości w jedną stronę robi diff
     * z połowy pliku. Wybór większości jest jedynym, który tego diffu nie robi.
     */
    private function detectEol(string $raw): string
    {
        $crlf = substr_count($raw, "\r\n");
        $lf = substr_count($raw, "\n") - $crlf;

        return $crlf > $lf ? "\r\n" : "\n";
    }

    /** Wcięcie z pierwszej wciętej linii: spacje albo tabulator, w takiej liczbie, jak w pliku. */
    private function detectIndent(string $raw): string
    {
        if (preg_match('/^([ \t]+)\S/m', $raw, $matches) === 1) {
            return $matches[1];
        }

        return '    ';
    }

    /**
     * Scalanie katalogu z tym, co w pliku już jest.
     *
     * Trzy reguły, każda z powodem:
     *
     * 1. **Klucze z pliku zostają na swoich miejscach** — także te, których katalog nie zna.
     *    Plik językowy w repozytorium bywa ręcznie uzupełniany (tekst jeszcze niezatwierdzony,
     *    klucz dodany lokalnie), a `pull` **nie jest właścicielem tego pliku**: skasowanie cudzej
     *    pracy bez pytania to najdroższy błąd, jaki to narzędzie mogłoby popełnić.
     * 2. **Nowe klucze dochodzą na końcu**, w kolejności z serwisu (alfabetycznej) — czyli diff
     *    pokazuje dokładnie to, co doszło.
     * 3. **`sort: key` porządkuje wszystko alfabetycznie** — to decyzja zespołu, więc wygrywa
     *    z kolejnością pliku i jest jedynym trybem, który robi jednorazowy diff porządkujący.
     *
     * @param array<string, string> $messages
     *
     * @return array<string, string>
     */
    private function merge(array $messages, LocaleFileStyle $style, LocaleWriteOptions $options): array
    {
        $merged = [];

        foreach ($style->order as $key) {
            $merged[$key] = $messages[$key] ?? $style->values[$key];
        }

        foreach ($messages as $key => $value) {
            if (!array_key_exists($key, $merged)) {
                $merged[$key] = $value;
            }
        }

        if ($options->sort === LocaleWriteOptions::SORT_KEY) {
            ksort($merged, SORT_STRING);
        }

        return $merged;
    }

    /**
     * @param array<string, string> $messages
     */
    private function render(array $messages, LocaleFileStyle $style, LocaleWriteOptions $options): string
    {
        $nested = $style->nestedWith($options);

        $contents = $this->format === 'json'
            ? $this->renderJson($messages, $nested, $style)
            : $this->renderPhp($messages, $nested, $style);

        if ($style->eol !== "\n") {
            $contents = str_replace("\n", $style->eol, $contents);
        }

        return $style->finalNewline ? $contents : rtrim($contents, "\r\n");
    }

    /**
     * @param array<string, string> $messages
     */
    private function renderJson(array $messages, bool $nested, LocaleFileStyle $style): string
    {
        $payload = $nested ? $this->nest($messages) : $messages;

        $encoded = json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        return $this->reindent($encoded, $style->indent) . "\n";
    }

    /**
     * `JSON_PRETTY_PRINT` zawsze wstawia cztery spacje, więc wcięcie z pliku odtwarzamy po fakcie.
     *
     * Poziom odczytujemy z liczby spacji na początku linii (wielokrotność czterech), a nie
     * z zagnieżdżenia w danych — dzięki temu ta sama funkcja obsługuje zapis płaski i drzewo.
     */
    private function reindent(string $json, string $indent): string
    {
        if ($indent === '    ') {
            return $json;
        }

        return (string) preg_replace_callback(
            '/^( +)/m',
            static fn (array $matches): string => str_repeat($indent, intdiv(strlen($matches[1]), 4)),
            $json,
        );
    }

    /**
     * Klucze płaskie → drzewo (`nav.title` → `nav: {title: …}`).
     *
     * Kolizja „klucz jest i liściem, i gałęzią” (`nav` oraz `nav.title` w jednym pliku) jest
     * w naszym katalogu niemożliwa, ale plik klienta może tak wyglądać — i wtedy wygrywa liść:
     * dłuższy klucz zostaje **pod swoją pełną nazwą** (`nav.title` jako klucz), zamiast cicho
     * zniknąć. Zniknięcie tekstu byłoby tu najgorszym możliwym rozwiązaniem.
     *
     * @param array<string, string> $messages
     *
     * @return array<string, mixed>
     */
    private function nest(array $messages): array
    {
        $tree = [];

        foreach ($messages as $key => $value) {
            $this->insert($tree, explode('.', $key), $key, $value);
        }

        return $tree;
    }

    /**
     * @param array<string, mixed> $tree
     * @param list<string> $segments
     */
    private function insert(array &$tree, array $segments, string $fullKey, string $value): void
    {
        $segment = array_shift($segments);

        if ($segment === null) {
            // Nieosiągalne z zewnątrz (klasa jest `final`, metoda prywatna): `explode('.', $key)`
            // daje niepustą listę, a rekurencja wchodzi niżej tylko z niepustymi segmentami.
            // Guard jest tu jednak potrzebny — i to nie dla PHPStanu samego w sobie, a dlatego,
            // że **PHPStan uruchomiony na PHP 8.5 nie zwęża `array_shift()` do `string`**, więc
            // bez niego `$segment` jest `string|null` w kluczu tablicy i bramka przewraca się
            // inaczej na 8.5, a inaczej na 8.3 (wersja analizatora zależy od wersji PHP).
            // `@var` kłamałby w typie, a ciche `?? ''` zamieniłoby błąd programisty w klucz pusty.
            throw new RuntimeException(sprintf('Klucz „%s” nie ma segmentów — to błąd w wywołaniu.', $fullKey));
        }

        if ($segments === []) {
            if (isset($tree[$segment]) && is_array($tree[$segment])) {
                $tree[$fullKey] = $value;

                return;
            }

            $tree[$segment] = $value;

            return;
        }

        if (isset($tree[$segment]) && !is_array($tree[$segment])) {
            $tree[$fullKey] = $value;

            return;
        }

        if (!isset($tree[$segment])) {
            $tree[$segment] = [];
        }

        $this->insert($tree[$segment], $segments, $fullKey, $value);
    }

    /**
     * Zapis w stylu, jakim posługują się projekty PHP: `return [ 'klucz' => 'tekst', ];`.
     *
     * `var_export` na wartościach (a nie na całej tablicy) daje poprawne escapowanie apostrofów
     * i znaków nowej linii bez pisania własnego escapera — a to jest miejsce, w którym własna
     * implementacja cicho psuje teksty z apostrofem („It's done”) albo z `\n`.
     *
     * @param array<string, string> $messages
     */
    private function renderPhp(array $messages, bool $nested, LocaleFileStyle $style): string
    {
        $payload = $nested ? $this->nest($messages) : $messages;

        $open = $style->longArraySyntax ? 'array(' : '[';
        $close = $style->longArraySyntax ? ')' : ']';

        $lines = ['<?php', ''];
        if ($style->strictTypes) {
            $lines[] = 'declare(strict_types=1);';
            $lines[] = '';
        }

        $lines[] = 'return ' . $open;

        foreach ($payload as $key => $value) {
            $lines = [...$lines, ...$this->phpLines((string) $key, $value, $style->indent, 1)];
        }

        $lines[] = $close . ';';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function phpLines(string $key, mixed $value, string $indent, int $level): array
    {
        $prefix = str_repeat($indent, $level);

        if (!is_array($value)) {
            return [sprintf('%s%s => %s,', $prefix, var_export($key, true), var_export($value, true))];
        }

        $lines = [sprintf('%s%s => [', $prefix, var_export($key, true))];
        foreach ($value as $childKey => $childValue) {
            $lines = [...$lines, ...$this->phpLines((string) $childKey, $childValue, $indent, $level + 1)];
        }

        $lines[] = $prefix . '],';

        return $lines;
    }

    /**
     * Plik → płaska mapa plus to, co o strukturze trzeba wiedzieć.
     *
     * @return array{messages: array<string, string>, nested: bool, order: list<string>}
     *
     * @throws RuntimeException
     */
    private function parse(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException(sprintf('Nie ma pliku %s.', $path));
        }

        $data = $this->format === 'json' ? $this->parseJson($path) : $this->parsePhp($path);

        $messages = [];
        $order = [];
        $nested = false;
        $this->flatten($data, '', $path, $messages, $order, $nested);

        return ['messages' => $messages, 'nested' => $nested, 'order' => $order];
    }

    /** @return array<mixed> */
    private function parseJson(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException(sprintf('Nie mogę odczytać %s.', $path));
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('%s nie jest poprawnym JSON-em.', $path));
        }

        return $decoded;
    }

    /** @return array<mixed> */
    private function parsePhp(string $path): array
    {
        try {
            /** @var mixed $loaded */
            $loaded = include $path;
        } catch (\Throwable $e) {
            throw new RuntimeException(sprintf('%s nie dał się wczytać: %s', $path, $e->getMessage()), 0, $e);
        }

        if (!is_array($loaded)) {
            throw new RuntimeException(sprintf(
                '%s nie zwraca tablicy — plik językowy PHP musi kończyć się `return [...];`.',
                $path,
            ));
        }

        return $loaded;
    }

    /**
     * Spłaszczanie zagnieżdżonej struktury do `klucz.kropka` — i **kolejność** z pliku.
     *
     * @param array<mixed> $data
     * @param array<string, string> $messages
     * @param list<string> $order
     */
    private function flatten(array $data, string $prefix, string $path, array &$messages, array &$order, bool &$nested): void
    {
        foreach ($data as $key => $value) {
            if (!is_string($key)) {
                throw new RuntimeException(sprintf('Klucz w %s musi być napisem.', $path));
            }

            $full = $prefix . $key;

            if (is_array($value)) {
                $nested = true;
                $this->flatten($value, $full . '.', $path, $messages, $order, $nested);
                continue;
            }

            if (!is_string($value)) {
                throw new RuntimeException(sprintf(
                    'Wartość klucza „%s” w %s nie jest napisem (jest %s). Zagnieżdżone tablice są obsługiwane, '
                    . 'ale liściem musi być tekst — liczby i wartości logiczne nie są tłumaczeniami.',
                    $full,
                    $path,
                    get_debug_type($value),
                ));
            }

            $messages[$full] = $value;
            $order[] = $full;
        }
    }
}
