<?php

declare(strict_types=1);

namespace BabelHub\Cli\Checks;

/**
 * Cztery **nazwane kontrole** bramki `validate` (Fala 1, pozycja 1.14).
 *
 * Nazwa jest kontraktem, nie etykietą: trafia do `--check=`, do `checks.run` w `.babelhub.yml`
 * i do nazwy kroku w CI („BabelHub / coverage”). Cel jest jeden i wprost z planu: **developer ma
 * wiedzieć, co blokuje merge, bez czytania logu**. Dlatego każda kontrola odpowiada na inne
 * pytanie, a nie jest innym filtrem tego samego:
 *
 * * `quality` — czy to, co jest przetłumaczone, jest poprawne (ICU, placeholdery, HTML, duplikaty),
 *   z progiem ważności;
 * * `coverage` — czy czegoś **nie brakuje** (klucz bez zatwierdzonego tłumaczenia);
 * * `acceptance` — czy coś **czeka na zatwierdzenie** (wartość jest, ale nie weszła do katalogu);
 * * `hardcoded-strings` — czy tekst nie został wpisany wprost w kodzie.
 *
 * Dwie pierwsze czytają ten sam raport QA (`POST …/qa`, zakres `qa:read`) i **nie mają własnej
 * definicji „brakujące”**: `coverage` to ustalenia o kodzie `missing` z tego samego dokumentu,
 * więc zmiana reguły po stronie serwisu przenosi się tu sama. `acceptance` czyta `GET …/status`
 * (`translations:read`), bo „ile czeka na zatwierdzenie” wie tylko stan katalogu — QA liczy
 * ustalenia wobec **zatwierdzonych** wartości i nie odróżnia „nikt nie przetłumaczył” od
 * „tłumaczenie jest, ale nikt go nie zatwierdził”. `hardcoded-strings` jako jedyna nie idzie
 * do serwisu i dlatego nie wymaga poświadczenia (może stać w CI przed `login`).
 *
 * Wyliczenie jest **jednym źródłem listy**: `--help`, walidacja `checks.run` i test kontraktu
 * fragmentów CI (`tests/Contract/CiFragmentTest`) czytają je stąd, więc literówka w nazwie
 * kontroli przewraca test w repozytorium BabelHub, a nie pipeline klienta.
 */
enum CheckName: string
{
    case Quality = 'quality';
    case Coverage = 'coverage';
    case Acceptance = 'acceptance';
    case Hardcoded = 'hardcoded-strings';

    /** @return list<string> nazwy do `--help`, walidacji konfiguracji i dokumentacji */
    public static function names(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Czy kontrola czyta serwis — czyli czy potrzebuje poświadczenia.
     *
     * Pytanie jest o **poświadczenie**, a nie o wygodę: `validate --check=hardcoded-strings`
     * musi działać na maszynie bez zalogowania (i w CI bez sekretu), więc komenda nie ma prawa
     * rozwiązywać poświadczenia, gdy o nic nie pyta serwisu.
     */
    public function needsServer(): bool
    {
        return $this !== self::Hardcoded;
    }
}
