<?php

declare(strict_types=1);

namespace BabelHub\Cli\Files;

/**
 * Styl pliku językowego **wykryty z tego, co leży w repozytorium** (pozycja 1.16b planu).
 *
 * To jest cała odpowiedź na „walkę narzędzia z łańcuchem budowania”: nie mamy własnego formatu,
 * mamy **cudzy**, odczytany z pliku. Zestaw pól nie jest przypadkowy — każde z nich było powodem
 * konfliktu w którymś z narzędzi tej kategorii:
 *
 * * `eol` i `finalNewline` — plik z CRLF przepisany na LF to diff w **każdej** linii;
 * * `indent` — zmiana dwóch spacji na cztery to diff w każdym wierszu, którego nikt nie czyta;
 * * `nested` — projekt z zagnieżdżonym JSON-em po `pull` dostawał płaski plik i **przestawał
 *   działać** (i18next czyta strukturę, nie kropki);
 * * `strictTypes` i `longArraySyntax` — `declare(strict_types=1)` i `array()` to decyzje projektu
 *   PHP; dopisanie pierwszego albo zamiana drugiego na `[]` to „poprawianie po swojemu”;
 * * `order` i `values` — kolejność kluczy i treść, która **już jest** w pliku: klucze nieznane
 *   katalogowi zostają na swoich miejscach, bo `pull` nie jest właścicielem tego pliku.
 *
 * Czego tu **nie ma**: stylu cudzysłowów w PHP. Wartości zapisujemy przez `var_export`, więc
 * zawsze w apostrofach — i to jest jedyne świadome odstępstwo, bo własny escaper to miejsce,
 * w którym narzędzia tej kategorii po cichu psują teksty z apostrofem albo znakiem nowej linii
 * (patrz `LocaleFile`).
 */
final readonly class LocaleFileStyle
{
    /**
     * @param list<string> $order kolejność kluczy z pliku (płaskie)
     * @param array<string, string> $values treść pliku (płaskie)
     */
    public function __construct(
        public string $eol = "\n",
        public bool $finalNewline = true,
        public string $indent = '    ',
        public bool $nested = false,
        public bool $strictTypes = true,
        public bool $longArraySyntax = false,
        public array $order = [],
        public array $values = [],
    ) {
    }

    /**
     * Styl pliku, którego jeszcze nie ma.
     *
     * Domyślne wartości to nasz format z Fali 1: cztery spacje, LF, końcowy znak nowej linii,
     * `declare(strict_types=1)` w PHP i płaska struktura. Wybrane dlatego, że tak wygląda
     * większość repozytoriów PHP/JS — a gdy projekt ma inaczej, plik już istnieje i wtedy
     * styl bierzemy z niego.
     */
    public static function defaults(bool $nested): self
    {
        return new self(nested: $nested);
    }

    /** Czy plik ma jakąkolwiek treść, którą trzeba uszanować. */
    public function isEmpty(): bool
    {
        return $this->order === [];
    }

    /** Zagnieżdżony zapis, gdy plik tak wygląda **albo** gdy zespół tak zdecydował. */
    public function nestedWith(LocaleWriteOptions $options): bool
    {
        return $options->nested || $this->nested;
    }
}
