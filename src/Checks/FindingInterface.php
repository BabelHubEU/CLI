<?php

declare(strict_types=1);

namespace BabelHub\Cli\Checks;

/**
 * Ustalenie kontroli w tym, co wspólne dla wszystkich czterech kontroli.
 *
 * Interfejs jest mały celowo: bramka i wydruk potrzebują z ustalenia **trzech rzeczy** — kodu
 * (kontrakt dla CI), wagi (jedyna rzecz decydująca o kodzie wyjścia) i zdania do pokazania.
 * Reszta kształtu należy do źródła: kontrola kodu ma `plik:linia` i tekst napisu, kontrola
 * katalogu — `język` i `klucz`. Zlepienie tego w jeden typ z czterema polami opcjonalnymi
 * skończyłoby się tym, że co druga kontrola ma puste `file`, a czytelnik nie wie, czy to brak
 * danych, czy brak zastosowania.
 */
interface FindingInterface
{
    /** Kod ustalenia — to po nim rozgałęzia się CI, nie po zdaniu. */
    public function code(): string;

    /** Waga rozstrzyga o bramce: `error` blokuje, `warning` tylko przy `--fail-on=warning`. */
    public function severity(): CheckSeverity;

    /** Miejsce ustalenia: `plik:linia` przy kontroli kodu, `język klucz` przy kontroli katalogu. */
    public function location(): string;

    public function message(): string;

    /** @return array<string, mixed> kształt, który jedzie w `--output=json` */
    public function toArray(): array;
}
