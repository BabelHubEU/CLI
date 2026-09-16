<?php

declare(strict_types=1);

namespace BabelHub\Cli\Checks;

/**
 * Wynik **jednej** nazwanej kontroli: ustalenia, liczby i werdykt przy danym progu.
 *
 * Werdykt nie jest polem, a metodą (`pass($threshold)`), i to jest cała różnica między tym
 * a „raportem z licznikami”: ten sam wynik może przejść przy progu `error` i nie przejść przy
 * `warning`. Trzymanie werdyktu w środku oznaczałoby, że kontrola musi wiedzieć, jaki próg
 * wybrał zespół — a próg jest decyzją wywołującego (flaga `--fail-on` / `checks.fail_on`).
 *
 * `summary` jest kształtem **właściwym dla kontroli** (`errors`/`warnings`/`info` dla QA,
 * `missing` dla pokrycia, `unapproved` dla zatwierdzeń), bo każda odpowiada na inne pytanie.
 * Wspólne jest to, co czyta maszyna: `check`, `pass`, `blocking`, `findings` (patrz `toArray()`).
 */
final readonly class CheckOutcome
{
    /**
     * @param list<FindingInterface> $findings
     * @param array<string, mixed> $summary
     * @param object|null $report dosłowne ciało odpowiedzi serwisu, gdy kontrola je ma
     *   (dziś: `quality`). Typ `object`, a nie tablica, **z powodu pustych map**: `json_decode`
     *   do tablicy zamienia `{}` na `[]`, więc ponowne zakodowanie zmieniłoby typ pola, które
     *   kontrakt API opisuje jako obiekt. `stdClass` przechodzi przez `json_encode` bez zmiany.
     */
    public function __construct(
        public CheckName $name,
        public array $findings = [],
        public array $summary = [],
        public ?object $report = null,
    ) {
    }

    public function countOf(CheckSeverity $severity): int
    {
        return count(array_filter(
            $this->findings,
            static fn (FindingInterface $finding): bool => $finding->severity() === $severity,
        ));
    }

    /** Ile ustaleń zatrzyma bramkę przy tym progu — liczba, którą CI pokazuje człowiekowi. */
    public function blocking(CheckSeverity $threshold): int
    {
        return count(array_filter(
            $this->findings,
            static fn (FindingInterface $finding): bool => $finding->severity()->blocks($threshold),
        ));
    }

    public function pass(CheckSeverity $threshold): bool
    {
        return $this->blocking($threshold) === 0;
    }

    /**
     * Jedno zdanie podsumowania — **wynik**, więc idzie na `stdout` także w trybie `plain`.
     *
     * Zaczyna się od nazwy kontroli, bo to ona stoi w nazwie kroku CI: człowiek czyta log i ma
     * widzieć to samo słowo, które widzi na liście checków w pull requeście („co blokuje merge”).
     * Liczby są zawsze pełne — skrócona bywa tylko lista ustaleń (`PREVIEW` w `CliValidate`).
     */
    public function summaryLine(): string
    {
        return match ($this->name) {
            CheckName::Quality => sprintf(
                'Kontrola jakości: %d błędów, %d ostrzeżeń, %d informacji.',
                $this->intAt('errors'),
                $this->intAt('warnings'),
                $this->intAt('info'),
            ),
            CheckName::Coverage => sprintf(
                'Kontrola coverage — brakujących tłumaczeń: %d%s.',
                $this->intAt('missing'),
                $this->localesBreakdown(),
            ),
            CheckName::Acceptance => sprintf(
                'Kontrola acceptance — niezatwierdzonych tłumaczeń: %d%s.',
                $this->intAt('unapproved'),
                $this->localesBreakdown(),
            ),
            CheckName::Hardcoded => sprintf(
                'Kontrola hardcoded-strings — ostrzeżeń: %d, plików: %d.',
                $this->intAt('warnings'),
                $this->intAt('files'),
            ),
        };
    }

    /**
     * Człon zdania dla nieudanej bramki: `quality (1 błędów)`, `coverage (12 brakujących tłumaczeń)`.
     *
     * Nazwa kontroli jest tu obowiązkowa — to jest cała wartość pozycji 1.14. Bez niej komunikat
     * „nie przeszła kontroli: 12” nie mówi, czego szukać, a przy czterech kontrolach w jednym
     * uruchomieniu nie da się nawet zgadnąć, którą powtórzyć.
     */
    public function blockingPhrase(CheckSeverity $threshold): string
    {
        $details = match ($this->name) {
            // Przy progu `warning` blokują obie wagi, więc zdanie musi pokazać obie liczby —
            // inaczej „3 błędów” przy dwóch błędach i jednym ostrzeżeniu wygląda na pomyłkę.
            CheckName::Quality => $threshold === CheckSeverity::Warning
                ? sprintf('%d błędów, %d ostrzeżeń', $this->intAt('errors'), $this->intAt('warnings'))
                : sprintf('%d błędów', $this->intAt('errors')),
            CheckName::Coverage => sprintf('%d brakujących tłumaczeń', $this->blocking($threshold)),
            CheckName::Acceptance => sprintf('%d niezatwierdzonych tłumaczeń', $this->blocking($threshold)),
            CheckName::Hardcoded => sprintf('%d ostrzeżeń', $this->blocking($threshold)),
        };

        return sprintf('%s (%s)', $this->name->value, $details);
    }

    /** @return array<string, mixed> */
    public function toArray(CheckSeverity $threshold): array
    {
        $document = [
            'check' => $this->name->value,
            'pass' => $this->pass($threshold),
            // Ile ustaleń zatrzymało bramkę **przy tym progu** — tego nie da się wyliczyć
            // z `summary`, bo próg jest decyzją wywołującego.
            'blocking' => $this->blocking($threshold),
            'summary' => self::jsonMap($this->summary),
            'findings' => array_map(
                static fn (FindingInterface $finding): array => $finding->toArray(),
                $this->findings,
            ),
        ];

        if ($this->report !== null) {
            // Dochodzi tylko tam, gdzie serwis zwraca cały dokument: nic z kontraktu API nie może
            // zginąć po drodze (`pass`, `locale`, `application` i `params` bez zmiany kształtu).
            $document['report'] = $this->report;
        }

        return $document;
    }

    /** Rozkład po językach do zdania podsumowania: ` (pl_PL: 7, de_DE: 5)`. */
    private function localesBreakdown(): string
    {
        $locales = $this->summary['locales'] ?? null;
        if (!is_array($locales) || $locales === []) {
            return '';
        }

        $parts = [];
        foreach ($locales as $code => $count) {
            $parts[] = sprintf('%s: %d', (string) $code, is_numeric($count) ? (int) $count : 0);
        }

        return ' (' . implode(', ', $parts) . ')';
    }

    private function intAt(string $key): int
    {
        $value = $this->summary[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Mapa → obiekt, lista → lista, w głąb.
     *
     * Powód jest jeden i już raz nas kosztował: pusta mapa PHP (`[]`) po `json_encode` staje się
     * pustą **listą**, czyli innym typem, niż obiecuje kontrakt (`summary`, `locales`). Dotyczy
     * to także zagnieżdżonych map, więc konwersja idzie rekurencyjnie — ale **list nie rusza**,
     * bo `findings` i `placeholders` mają zostać listami.
     *
     * @param array<string, mixed> $map
     */
    private static function jsonMap(array $map): object
    {
        return (object) array_map(
            static fn (mixed $value): mixed => is_array($value) && !array_is_list($value) ? self::jsonMap($value) : $value,
            $map,
        );
    }
}
