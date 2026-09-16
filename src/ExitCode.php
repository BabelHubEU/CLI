<?php

declare(strict_types=1);

namespace BabelHub\Cli;

/**
 * Kontrakt kodów wyjścia — **jedna tabela, której CLI faktycznie przestrzega**.
 *
 * To jest miejsce z planu (1.16), w którym możemy być sprawdzalnie lepsi od całej kategorii:
 * u konkurencji `crowdin download` potrafi wyjść kodem `0` mimo błędu („the job appears to have
 * passed”), a skrypt CI, który dostał zero, nie ma już jak się o tym dowiedzieć. Nasza reguła
 * jest jedna i nie ma wyjątków: **`0` znaczy dokładnie „zrobione”, wszystko inne znaczy „nie
 * zrobione”**. Dotyczy to także powodzenia częściowego — `push`, który wysłał 90 z 100 kluczy,
 * nie może udawać sukcesu, bo wtedy nikt nie wróci do brakujących dziesięciu.
 *
 * Kody są **różne dla różnych przyczyn**, bo od tego zależy reakcja: `4` (limit) znaczy „poczekaj
 * i powtórz”, `3` (uwierzytelnienie) znaczy „napraw poświadczenie”, `2` (użycie) znaczy „popraw
 * wywołanie”, a `6` (kontrole) znaczy „popraw tłumaczenia”. Wszystkie są udokumentowane
 * w `docs/cli.md` i pokryte testami — tabela, której nikt nie sprawdza, rozjeżdża się
 * przy pierwszej zmianie.
 */
enum ExitCode: int
{
    /** Zrobione. Jedyne zero w tym enumie. */
    case Success = 0;

    /** Błąd nieprzewidziany: sieć, dysk, wyjątek. Komunikat mówi, co się stało. */
    case Failure = 1;

    /** Błąd użycia albo konfiguracji: nieznana flaga, brak `.babelhub.json`, brak poświadczenia. */
    case Usage = 2;

    /** `401`/`403` z API: poświadczenie nieznane, wygasłe, odwołane albo bez zakresu. */
    case Unauthorized = 3;

    /** `429`: limiter kazał poczekać. Osobny kod, bo to jedyny błąd, który mija sam. */
    case RateLimited = 4;

    /** Inne odrzucenie przez API: `404` (nie ma projektu), `422` (niepoprawne dane). */
    case Rejected = 5;

    /** Kontrole przeszły i **coś znalazły** (`validate`, niepełny `push`). Bramka CI. */
    case CheckFailed = 6;

    /**
     * Parowanie czeka na człowieka: CLI wypisało kod i adres, ale poświadczenia jeszcze nie ma.
     *
     * Osobny kod, bo to jedyny stan, w którym **nic nie jest zepsute** i wystarczy dokończyć
     * krok w przeglądarce. Zero byłoby tu kłamstwem (skrypt uznałby, że jest zalogowany),
     * a `1` gubiłoby informację „wróć tu z `--complete=<url>`”.
     */
    case Pending = 7;

    public function isSuccess(): bool
    {
        return $this === self::Success;
    }
}
