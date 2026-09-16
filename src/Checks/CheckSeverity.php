<?php

declare(strict_types=1);

namespace BabelHub\Cli\Checks;

/**
 * Waga ustalenia — **jedyna rzecz, która decyduje o bramce**.
 *
 * Ta sama zasada co przy QA z serwisu: kod jest kontraktem (CI rozgałęzia się po nim), a waga
 * decyduje, czy merge jest zablokowany. `hardcoded_string` jest **ostrzeżeniem**, bo to heurystyka:
 * „wygląda na tekst dla człowieka” nie jest tym samym co „na pewno tekst dla człowieka”, a fałszywy
 * alarm blokujący merge uczy ludzi obchodzenia bramki — czyli kosztuje więcej, niż daje.
 */
enum CheckSeverity: string
{
    case Error = 'error';
    case Warning = 'warning';

    /**
     * Waga `info` dochodzi razem z kontrolami czytającymi raport QA z serwisu (1.14).
     *
     * Nie jest ozdobą: serwis ma **trzy** wagi (`QualitySeverity`: `error`/`warning`/`info`)
     * i wysyła je w ciele odpowiedzi. Bez tej pozycji ustalenie o wadze `info` nie miałoby
     * swojego przypadku — `tryFrom()` zwracałby `null`, a ustalenie zniknęłoby z wydruku
     * (albo, gorzej, zostałoby policzone jako blokujące). `info` **nigdy** nie blokuje,
     * także przy `--fail-on=warning`: to obserwacja, a nie problem do naprawienia.
     */
    case Info = 'info';

    /** Czy ta waga blokuje przy progu `--fail-on` (domyślnie: tylko `error`). */
    public function blocks(CheckSeverity $threshold): bool
    {
        return match ($this) {
            self::Error => true,
            self::Warning => $threshold === self::Warning,
            self::Info => false,
        };
    }
}
