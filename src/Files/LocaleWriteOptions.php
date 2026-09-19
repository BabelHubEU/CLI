<?php

declare(strict_types=1);

namespace BabelHub\Cli\Files;

/**
 * Opcje zapisu pliku językowego — **to, co ustawia projekt**, a nie to, co wygląda plik.
 *
 * Rozróżnienie jest istotne, bo decyzje pochodzą z dwóch różnych miejsc i mają różną wagę:
 *
 * * **styl** (wcięcia, końce linii, kolejność kluczy, płasko czy zagnieżdżone) wykrywamy z pliku,
 *   który w repozytorium już jest — on należy do klienta, a nie do nas (1.16b);
 * * **opcje z konfiguracji** są decyzją zespołu: „piszemy zagnieżdżone, bo tak czyta to i18next”
 *   albo „sortuj klucze alfabetycznie, bo tak chce review”. Ustawione wprost **wygrywają**
 *   z tym, co widać w pliku — inaczej nie dałoby się zmienić formatu istniejącego projektu.
 *
 * `sort` jest listą zamkniętą, a nie polem `true|false`: w narzędziach tej kategorii „sortowanie”
 * znaczy zwykle „alfabetycznie”, a tu chodzi o wybór między **kolejnością z serwisu** a
 * **kolejnością z pliku** — i tylko jedna z nich jest domyślna, bo tylko ona nie robi diffu.
 *
 * `rootKey` jest **deklaracją kształtu, nie stylem**, i dlatego siedzi tutaj, a nie w wykrytym
 * stylu pliku: mówi, że komunikaty mieszkają pod kluczem najwyższego poziomu
 * (`{"locale": "pl_PL", "translations": {"klucz": "tekst"}}`). Tego nie da się bezpiecznie
 * zgadnąć — plik może mieć przestrzeń nazw o tej samej nazwie — a pomyłka jest cicha w obie
 * strony: `push` wysłałby klucze `translations.…`, a `pull` dołożyłby drugą kopię katalogu
 * zamiast go zaktualizować. Wykrywany zostaje **styl w środku** koperty (płasko czy
 * zagnieżdżone), a rodzeństwo klucza (`locale`, `meta`, …) wraca do pliku nietknięte.
 */
final readonly class LocaleWriteOptions
{
    /** Kolejność z pliku: istniejące klucze zostają na miejscu, nowe dochodzą na końcu. */
    public const SORT_FILE = 'file';

    /** Kolejność alfabetyczna — świadoma decyzja zespołu, kosztem jednorazowego diffu. */
    public const SORT_KEY = 'key';

    /** @var list<string> */
    public const SORTS = [self::SORT_FILE, self::SORT_KEY];

    public function __construct(
        public bool $nested = false,
        public string $sort = self::SORT_FILE,
        public ?string $rootKey = null,
    ) {
    }
}
