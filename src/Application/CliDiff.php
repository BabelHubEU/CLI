<?php

declare(strict_types=1);

namespace BabelHub\Cli\Application;

use BabelHub\Cli\Output\Printer;

/**
 * Wydruk diffu dla człowieka i dla `grep`-a — logika osobno od komendy, żeby dała się przetestować
 * bez sieci i bez konfiguracji projektu (`tests/Cli/CliDiffCommandTest.php`).
 *
 * Cztery decyzje warte nazwania:
 *
 * * **jedna linia na klucz, prefiks mówi który to kubełek** (`new a.one`, `changed b.two`) —
 *   dzięki temu `--output=plain` nadaje się do `grep`/`awk`, a w trybie `text` ta sama linia
 *   jest czytelna; nagłówki sekcji są ozdobą i dlatego lecą przez `result()` (tylko `text`);
 * * **liczby są sumami z serwisu, a nie długościami list** — listy są ucięte do 500 pozycji,
 *   więc „500” bez znacznika ucięcia byłoby liczbą kłamiącą; gdy serwis mówi `truncated`,
 *   wydruk dopisuje `+`;
 * * **puste kubełki zostają na wydruku** — „removed: 0” to informacja, a brak sekcji to pytanie
 *   „a usunięte?”;
 * * **tekst pokazuje po 20 pozycji na kubełek**, resztę streszcza. Diff po dużym refaktorze ma
 *   tysiące linii i nikt tego nie czyta — od pełnej listy jest `--output=json`.
 */
final readonly class CliDiff
{
    private const PREVIEW = 20;

    /** Nazwy kubełków w kolejności, w jakiej czyta je człowiek: doszło → zmieniło się → zniknęło. */
    private const BUCKETS = ['new', 'changed', 'removed'];

    public function __construct(private Printer $printer)
    {
    }

    /** @param array<string, mixed> $diff odpowiedź `GET …/keys/diff` */
    public function print(array $diff, string $project, string $since): void
    {
        $counts = $this->map($diff, 'counts');
        $truncated = $this->map($diff, 'truncated');
        $keys = $this->map($diff, 'keys');

        $this->printer->result('');
        $this->printer->result(sprintf('<info>%s</info> — zmiany w kluczach od %s', $project, $since));
        $this->printer->result('');

        // Liczby przez `plain`, bo w trybie `plain` są jedynym podsumowaniem, jakie zostaje.
        $this->printer->plain(sprintf('New:      %s', $this->count($counts, $truncated, 'new')));
        $this->printer->plain(sprintf('Changed:  %s', $this->count($counts, $truncated, 'changed')));
        $this->printer->plain(sprintf('Removed:  %s', $this->count($counts, $truncated, 'removed')));
        $this->printer->plain(sprintf('Total:    %d', $this->int($counts, 'total')));

        foreach (self::BUCKETS as $bucket) {
            $this->section($bucket, $this->rows($keys, $bucket), $this->int($counts, $bucket));
        }

        $total = $this->int($counts, 'total');
        if ($total === 0) {
            $this->printer->result('Nic się nie zmieniło w tym okresie.');
        }

        $this->legend();
    }

    /** @param list<array<string, mixed>> $rows */
    private function section(string $bucket, array $rows, int $total): void
    {
        if ($total === 0) {
            return;
        }

        $this->printer->result('');
        $this->printer->result($this->title($bucket) . ':');
        $this->printer->result('');

        foreach (array_slice($rows, 0, self::PREVIEW) as $row) {
            $this->printer->plain(sprintf('%s %s%s', $bucket, $this->key($row), $this->detail($bucket, $row)));
        }

        if ($total > self::PREVIEW) {
            $this->printer->result(sprintf('… i %d więcej (pełna lista: --output=json)', $total - self::PREVIEW));
        }
    }

    /** Co znaczy kubełek — raz, na końcu, zamiast powtarzać to przy każdej linii. */
    private function legend(): void
    {
        $this->printer->result('');
        $this->printer->result('new     — klucz pojawił się w kodzie, nie ma jeszcze tłumaczenia');
        $this->printer->result('changed — zmienił się tekst źródłowy; istniejące tłumaczenie może być nieaktualne');
        $this->printer->result('removed — klucza nie ma już w kodzie; w katalogu zostaje razem z tłumaczeniami');
    }

    private function title(string $bucket): string
    {
        return match ($bucket) {
            'new' => 'Nowe klucze',
            'changed' => 'Zmienione (tekst źródłowy)',
            default => 'Usunięte z kodu',
        };
    }

    /** @param array<string, mixed> $row */
    private function key(array $row): string
    {
        $key = $row['key'] ?? '';

        return is_string($key) ? $key : '';
    }

    /** @param array<string, mixed> $row */
    private function detail(string $bucket, array $row): string
    {
        $field = match ($bucket) {
            'new' => 'first_seen_at',
            'changed' => 'source_changed_at',
            default => 'removed_at',
        };

        $value = $row[$field] ?? null;
        $origin = $row['origin'] ?? null;

        $parts = [];
        if (is_string($value) && $value !== '') {
            $parts[] = sprintf('(%s)', $value);
        }
        if (is_string($origin) && $origin !== '') {
            $parts[] = sprintf('[%s]', $origin);
        }

        return $parts === [] ? '' : '  ' . implode(' ', $parts);
    }

    /**
     * @param array<string, mixed> $source
     *
     * @return array<string, mixed>
     */
    private function map(array $source, string $name): array
    {
        $value = $source[$name] ?? null;

        return is_array($value) ? $value : [];
    }

    /** @param array<string, mixed> $counts */
    private function int(array $counts, string $name): int
    {
        $value = $counts[$name] ?? 0;

        return is_int($value) ? $value : 0;
    }

    /**
     * @param array<string, mixed> $counts
     * @param array<string, mixed> $truncated
     */
    private function count(array $counts, array $truncated, string $bucket): string
    {
        $count = $this->int($counts, $bucket);

        return ($truncated[$bucket] ?? false) === true ? $count . '+' : (string) $count;
    }

    /**
     * @param array<string, mixed> $keys
     *
     * @return list<array<string, mixed>>
     */
    private function rows(array $keys, string $bucket): array
    {
        $rows = $keys[$bucket] ?? [];
        if (!is_array($rows)) {
            return [];
        }

        $normalized = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $normalized[] = $row;
            }
        }

        return $normalized;
    }
}
