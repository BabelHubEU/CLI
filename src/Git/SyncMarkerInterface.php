<?php

declare(strict_types=1);

namespace BabelHub\Cli\Git;

/**
 * Znacznik synchronizacji w Git (1.18): „ten stan kodu już poszedł do serwisu”.
 *
 * **Po co to istnieje.** `push` jest idempotentny, więc powtarzanie go nie psuje danych — ale
 * *kosztuje*: każde uruchomienie w CI wysyła cały katalog, a serwis porównuje go klucz po kluczu.
 * Przy projekcie z 2 500 kluczami i pipeline'em odpalającym się przy każdym commicie (także
 * w plikach, które tłumaczeń nie dotyczą) robi się z tego ruch, którego nikt nie potrzebuje —
 * a „darmowy `sync`” jest argumentem sprzedażowym, nie ozdobnikiem.
 *
 * Znacznik to **tag w repozytorium klienta** (`babelhub/sync/<gałąź>`), przesuwany po udanym
 * pushu. Nie trzymamy go w pliku roboczym, bo plik byłby albo commitowany (i wracałby jako konflikt
 * w każdym pull requeście), albo ignorowany (i wtedy znika w świeżym klonie, czyli dokładnie tam,
 * gdzie CI go potrzebuje). Tag jest lokalny, nie wymaga uprawnień do zapisu w zdalnym repo
 * i widać go w `git tag`.
 *
 * Port, a nie klasa wołająca `git` wprost: `CliPush` musi dać się przetestować bez repozytorium
 * (i bez `git` na maszynie), a zachowanie „pomiń, bo już wysłane” jest regułą produktu, nie
 * szczegółem implementacji.
 */
interface SyncMarkerInterface
{
    /**
     * Stan kodu w katalogu projektu.
     *
     * `null` znaczy „nie wiem” — katalog nie jest repozytorium, brakuje `git`, HEAD jest
     * odłączony (detached) albo pliki nie są w indeksie. Wtedy **nie pomijamy pushu i nie
     * stawiamy znacznika**: brak informacji nie może udawać „już wysłane”, bo po cichu
     * wstrzymałby synchronizację.
     *
     * @param list<string> $paths ścieżki, które `push` naprawdę czyta (drzewo skanu + plik źródłowy)
     */
    public function revision(string $root, array $paths): ?CodeRevision;

    /** Commit zapisany w znaczniku dla tej gałęzi albo `null`, gdy znacznika nie ma. */
    public function synced(string $root, string $branch): ?string;

    /** Przesuwa znacznik tej gałęzi na commit (tworzy go, jeśli nie istnieje). */
    public function remember(string $root, string $branch, string $commit): void;

    /**
     * Nazwa znacznika dla gałęzi — do komunikatów i do wyniku maszynowego.
     *
     * Wychodzi z portu, bo to jedyne miejsce, które wie, **gdzie** znacznik mieszka (tag w git-cie,
     * plik, cokolwiek innego). `CliPush` nie może zgadywać `babelhub/sync/…`, bo wtedy druga
     * implementacja znacznika kłamałaby w komunikatach.
     */
    public function markerLabel(string $branch): string;

    /**
     * Wysyła znacznik do zdalnego repozytorium (`git push --force origin refs/tags/…`).
     *
     * **Po co, skoro tag jest lokalny.** Znacznik działa tam, gdzie klon przetrwa między
     * przebiegami: lokalnie, w GitLab CI z `GIT_STRATEGY=fetch`, na runnerach własnych. Świeży,
     * płytki klon (domyślne GitHub Actions) nie ma tagów, więc mechanizm nic nie oszczędza —
     * chyba że znacznik dojedzie do zdalnego repo. To jest **opcja** (`--push-tag`), a nie
     * domyślne zachowanie: zapis do zdalnego repo to uprawnienia i widoczny ślad, więc
     * decyzja należy do zespołu, a nie do narzędzia.
     *
     * Zwraca `false`, gdy nie udało się wysłać (brak uprawnień, brak zdalnego repo) — to
     * **nie jest** niepowodzenie pushu: tłumaczenia są już w serwisie.
     */
    public function publish(string $root, string $branch): bool;
}
