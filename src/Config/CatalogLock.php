<?php

declare(strict_types=1);

namespace BabelHub\Cli\Config;

use RuntimeException;

/**
 * `.babelhub.lock.json` — **które wersje katalogów są w tym commicie**.
 *
 * To jest odpowiedź na pytanie z planu (1.16a): „czy umiesz dać mi tłumaczenia dla konkretnego
 * commita?”. Odpowiedź brzmi: tak, i to bez pytania serwera — bo pliki językowe **i** ten plik
 * leżą w repozytorium, więc `git checkout <commit>` daje komplet. Lock dokłada do tego to, czego
 * pliki same nie mówią: z której wersji katalogu powstały i czy aby na pewno są z nią zgodne
 * (`babelhub pull --check`).
 *
 * **Czego nie robimy i dlaczego: `pull --version=N` nie odtwarza dowolnej przeszłej wersji.**
 * Plan (1.16a) przewidywał eksport konkretnej wersji, ale serwis trzyma **jedną** wersję na
 * (projekt, język) — historię treści dopiero planujemy (Fala 3, `translation_revisions`).
 * Udawanie, że umiemy odtworzyć wersję 7, gdy w bazie jest 12, byłoby dokładnie tym rodzajem
 * obietnicy, która psuje zaufanie: polecenie działa, tylko zwraca coś innego. Pin jest więc
 * **w commicie** (pliki + lock), a `--check` tego pilnuje — a prawdziwy eksport historii
 * wejdzie razem z tabelą rewizji.
 *
 * Osobny plik, a nie nagłówek w pliku językowym: JSON-a nie da się skomentować, a format pliku
 * należy do klienta (1.16b — „pull zapisuje w formacie, w jakim przeczytał”) i nie wolno nam
 * wstawiać do niego własnych metadanych.
 */
final readonly class CatalogLock
{
    public const FILE_NAME = '.babelhub.lock.json';

    /**
     * @param array<string, array{version: int, keys: int, pulled_at: string}> $locales
     */
    public function __construct(
        public string $project,
        public array $locales,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): ?self
    {
        $project = $data['project'] ?? null;
        $locales = $data['locales'] ?? null;

        if (!is_string($project) || $project === '' || !is_array($locales)) {
            return null;
        }

        $normalized = [];
        foreach ($locales as $code => $entry) {
            if (!is_string($code) || !is_array($entry)) {
                continue;
            }

            $normalized[$code] = [
                'version' => is_int($entry['version'] ?? null) ? $entry['version'] : 0,
                'keys' => is_int($entry['keys'] ?? null) ? $entry['keys'] : 0,
                'pulled_at' => is_string($entry['pulled_at'] ?? null) ? $entry['pulled_at'] : '',
            ];
        }

        return new self($project, $normalized);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'project' => $this->project,
            'locales' => $this->locales,
        ];
    }

    public function versionFor(string $locale): ?int
    {
        return $this->locales[$locale]['version'] ?? null;
    }

    /**
     * Ile kluczy miał katalog przy ostatnim zapisie.
     *
     * Potrzebne przy odpowiedzi `304`: wtedy **nie wiemy**, ile kluczy jest w katalogu (bo
     * serwis nie przysłał treści), więc przepisujemy liczbę z poprzedniego locka. Zerowanie
     * jej byłoby cichym kłamstwem w pliku, który ma opisywać stan repozytorium — a właśnie
     * tak wyszło na żywym przebiegu: drugi `pull` (same `304`) zapisał `keys: 0` przy
     * katalogu, który ma 91 kluczy.
     */
    public function keysFor(string $locale): ?int
    {
        return $this->locales[$locale]['keys'] ?? null;
    }

    /**
     * Kiedy plik tego języka został **ostatnio zapisany** przez `pull` (`''` = nigdy).
     *
     * Potrzebne, bo `pull` buduje nowy lock od zera: gdy serwis odpowie `304` (albo katalog jest
     * pusty) nic nie zapisano, więc data musi przyjść z **poprzedniego** locka, a nie z bieżącego
     * czasu. Wersja tej metody bez tego rozróżnienia kosztowała realną pętlę w CI: `304`
     * przepisywało `pulled_at` bieżącym czasem, lock zmieniał się przy każdym przebiegu `sync`,
     * więc każdy przebieg robił commit, a każdy commit wywoływał kolejny pipeline. Zmierzone
     * na LearnHubie: dziesięć commitów bota w pięć minut (20:04:19–20:08:59), każdy zmieniający
     * **wyłącznie** `pulled_at`.
     */
    public function pulledAtFor(string $locale): string
    {
        return $this->locales[$locale]['pulled_at'] ?? '';
    }

    /** @return list<string> */
    public function localeCodes(): array
    {
        return array_keys($this->locales);
    }

    /**
     * Nowy lock z dopisanym językiem — **niemutujący**, bo klasa jest `readonly`.
     *
     * Zwracanie nowej wartości, a nie zmiana w miejscu, ma tę zaletę, że „lock przed pull”
     * i „lock po pull” mogą istnieć obok siebie: `--check` porównuje oba i mówi, co się
     * rozjechało, zamiast nadpisywać dowód rozbieżności.
     */
    public function withLocale(string $locale, int $version, int $keys, string $pulledAt): self
    {
        $locales = $this->locales;
        $locales[$locale] = ['version' => $version, 'keys' => $keys, 'pulled_at' => $pulledAt];

        return new self($this->project, $locales);
    }
}
