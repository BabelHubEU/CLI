<?php

declare(strict_types=1);

namespace BabelHub\Cli\Application;

use BabelHub\Cli\Config\ProjectConfig;
use BabelHub\Cli\ExitCode;
use BabelHub\Cli\Files\LocaleFile;
use BabelHub\Cli\Git\SyncMarkerInterface;
use BabelHub\Cli\Http\ApiClientInterface;
use BabelHub\Cli\Output\Printer;
use BabelHub\Cli\Scan\PhpTwigScanner;

/**
 * `babelhub push` — stan kodu jedzie do serwisu jednym żądaniem.
 *
 * Trzy decyzje, które trzeba znać, czytając ten kod:
 *
 * 1. **Partia to suma: klucze ze skanu ∪ klucze z pliku źródłowego.** Sam skan nie wystarcza,
 *    bo klucz może być użyty dynamicznie (`t('a.' ~ $x)`), a wtedy jest w pliku językowym i nie
 *    ma go w kodzie jako literał. Wysłanie samego skanu oznaczyłoby taki klucz jako „zniknął
 *    z kodu” i — przy domyślnym `mark_missing_as_removed` — zgasiło czyjąś pracę. Plik źródłowy
 *    jest deklaracją dewelopera, więc ma głos.
 * 2. **Powtórzenie nic nie psuje.** Endpoint jest idempotentny (klucz unikalny w projekcie,
 *    wartości porównywane), więc `push` można uruchamiać po każdym commicie — a odpowiedź mówi
 *    wprost, ile pozycji zostało `unchanged`, co jest dowodem, że pipeline nie podbija wersji
 *    katalogu bez powodu.
 * 3. **Pominięte klucze to nie sukces.** Jeśli serwis odrzucił część partii (zły format klucza),
 *    CLI kończy się kodem `6`, a nie `0` — inaczej „push przeszedł” znaczyłoby „nie wiem, czy
 *    wszystko dotarło”, czyli dokładnie to, czego zabrania kontrakt kodów wyjścia (1.16).
 * 4. **Ten sam commit nie jedzie dwa razy** (1.18). Znacznik w git-cie (`babelhub/sync/<gałąź>`)
 *    mówi, co już poszło; gdy nic się nie zmieniło, `push` **nie wykonuje żadnego żądania**
 *    i kończy się `0` z dokumentem `{"skipped":true,…}`. Znacznik przesuwa się tylko po pushu
 *    **kompletnym** (zero pominiętych kluczy), więc nieudany przebieg nie zamraża stanu —
 *    następne uruchomienie wyśle wszystko jeszcze raz.
 */
final readonly class CliPush
{
    public function __construct(
        private ApiClientInterface $client,
        private Printer $printer,
        private PhpTwigScanner $scanner,
        private SyncMarkerInterface $marker,
    ) {
    }

    public function run(
        ProjectConfig $config,
        string $root,
        bool $dryRun = false,
        bool $markMissingAsRemoved = true,
        bool $unapproveChanged = false,
        bool $force = false,
        bool $publishTag = false,
    ): ExitCode {
        $scan = $this->scanner->scan($root, $config->scanPaths);

        $sourcePath = $config->fileFor($config->sourceLocale, $root);
        $messages = [];
        $sourceFileExists = is_file($sourcePath);
        if ($sourceFileExists) {
            $messages = (new LocaleFile($config->fileFormat))->read($sourcePath);
        } else {
            // Brak pliku źródłowego to nie błąd: projekt może dopiero zaczynać albo trzymać
            // teksty gdzie indziej. Mówimy o tym wprost, bo bez tej informacji „wysłałem 240
            // kluczy, ale tłumaczenia źródłowe nie przyszły” wygląda jak awaria serwisu.
            $this->printer->warn(sprintf(
                'Nie ma pliku źródłowego %s — wysyłam same klucze, bez tekstów w %s.',
                $sourcePath,
                $config->sourceLocale,
            ));
        }

        $keys = $scan->keys();
        foreach (array_keys($messages) as $key) {
            if (!in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }
        sort($keys);

        if ($keys === []) {
            $this->printer->error(sprintf(
                'Skan nie znalazł żadnego klucza w %s i plik źródłowy jest pusty lub go nie ma. ' .
                'Sprawdź "scan.paths" w .babelhub.json.',
                implode(', ', $config->scanPaths),
            ));

            return ExitCode::Usage;
        }

        $payload = [];
        foreach ($keys as $key) {
            $payload[] = ['key' => $key, 'source' => $messages[$key] ?? null];
        }

        $this->printer->info(sprintf(
            'Skan: %d plików, %d kluczy; teksty źródłowe: %d (%s).',
            $scan->filesScanned(),
            $scan->count(),
            count($messages),
            $config->sourceLocale,
        ));

        // Znacznik synchronizacji pytamy **przed** dry-runem: `--dry-run` ma pokazać to, co
        // naprawdę by się stało, a przy niezmienionym commicie nie stałoby się nic.
        $revision = $this->marker->revision($root, $this->watchedPaths($config, $root));
        $alreadySynced = $revision !== null
            && $revision->pathsUnchanged
            && $this->marker->synced($root, $revision->branch) === $revision->commit;

        if (!$force && $alreadySynced) {
            $marker = $this->marker->markerLabel($revision->branch);
            $this->printer->info(sprintf('Znacznik %s wskazuje na %s.', $marker, $revision->label()));
            $this->printer->json([
                'skipped' => true,
                'reason' => 'git',
                'project' => $config->project,
                'commit' => $revision->commit,
                'branch' => $revision->branch,
                'marker' => $marker,
            ]);
            $this->printer->result(sprintf(
                'Nic do wysłania: %s jest już zsynchronizowany (%s). Użyj --force, żeby wysłać ponownie.',
                $revision->label(),
                $marker,
            ));

            return ExitCode::Success;
        }

        if ($dryRun) {
            $this->printer->json([
                'dry_run' => true,
                'project' => $config->project,
                'source_locale' => $config->sourceLocale,
                'keys' => count($payload),
                'with_source' => count(array_filter($payload, static fn (array $row): bool => $row['source'] !== null)),
                'unapprove_changed' => $unapproveChanged,
            ]);
            $this->printer->result(sprintf(
                '[dry-run] Wysłałbym %d kluczy do projektu %s (z tekstem źródłowym: %d).',
                count($payload),
                $config->project,
                count(array_filter($payload, static fn (array $row): bool => $row['source'] !== null)),
            ));

            return ExitCode::Success;
        }

        $response = $this->client->post('/api/v1/projects/' . rawurlencode($config->project) . '/sync', [
            'source_locale' => $config->sourceLocale,
            'keys' => $payload,
            'mark_missing_as_removed' => $markMissingAsRemoved,
            // Odpowiedź na pytanie z planu (1.19): czy zmiana tekstu źródłowego unieważnia
            // czyjeś zatwierdzenie. Domyślnie nie — patrz `SyncTranslationKeys::$unapproveChanged`.
            'unapprove_changed' => $unapproveChanged,
        ]);

        if (!$response->isSuccess()) {
            $this->printer->error($response->message());

            return $response->exitCode();
        }

        $result = $response->json();
        // Odpowiedź serwisu przepisujemy dosłownie (`jsonBody`), a nie przez ponowne kodowanie:
        // pusta mapa `skipped` po drodze przez `json_decode`+`json_encode` stałaby się `[]`.
        $this->printer->jsonBody($response->body);

        $created = $this->number($result, 'created');
        $updated = $this->number($result, 'updated');
        $removed = $this->number($result, 'removed');
        $unchanged = $this->number($result, 'unchanged');
        $sourceChanged = $this->number($result, 'source_changed');

        $this->printer->result(sprintf(
            '%s: nowe %d, zmienione %d, usunięte %d, bez zmian %d (teksty źródłowe zmienione: %d)',
            (string) ($result['application'] ?? $config->project),
            $created,
            $updated,
            $removed,
            $unchanged,
            $sourceChanged,
        ));

        $skipped = $result['skipped'] ?? [];
        if (is_array($skipped) && $skipped !== []) {
            // Kod `6`, nie `0`: część partii nie dotarła, a CI ma się o tym dowiedzieć.
            foreach ($skipped as $key => $reason) {
                $this->printer->error(sprintf('Pominięty %s: %s', (string) $key, (string) $reason));
            }

            $this->printer->error(sprintf(
                'Serwis pominął %d pozycji — push nie jest kompletny.',
                count($skipped),
            ));

            return ExitCode::CheckFailed;
        }

        // Znacznik przesuwamy **dopiero tutaj**: push kompletny (kod `0`, zero pominiętych).
        // Gdyby stanął wyżej, nieudany przebieg zamroziłby stan i następne uruchomienie
        // powiedziałoby „już wysłane” o partii, która dotarła tylko częściowo.
        if ($revision !== null) {
            $this->marker->remember($root, $revision->branch, $revision->commit);
            $this->printer->info(sprintf(
                'Znacznik %s → %s.',
                $this->marker->markerLabel($revision->branch),
                $revision->label(),
            ));

            if ($publishTag && !$this->marker->publish($root, $revision->branch)) {
                // Niepowodzenie wysłania znacznika **nie jest** niepowodzeniem pushu: tłumaczenia
                // są w serwisie, a znacznik jest optymalizacją. Mówimy o tym wprost, bo cicha
                // różnica między „działa” a „działa u mnie lokalnie” kosztowałaby pół dnia.
                $this->printer->warn(sprintf(
                    'Nie udało się wysłać znacznika %s do zdalnego repozytorium — znacznik został lokalnie. ' .
                    'W świeżym klonie CI push znowu wyśle cały stan.',
                    $this->marker->markerLabel($revision->branch),
                ));
            }
        }

        return ExitCode::Success;
    }

    /**
     * Ścieżki, które `push` naprawdę czyta: drzewo skanu i plik źródłowy.
     *
     * Tylko one decydują o „nic się nie zmieniło” — `pull` zostawia zmienione pliki języków
     * docelowych, które do tej partii nie wchodzą.
     *
     * @return list<string>
     */
    private function watchedPaths(ProjectConfig $config, string $root): array
    {
        $paths = $config->scanPaths;

        $source = $config->fileFor($config->sourceLocale, $root);
        $prefix = rtrim($root, '/') . '/';
        $paths[] = str_starts_with($source, $prefix) ? substr($source, strlen($prefix)) : $source;

        return array_values(array_unique($paths));
    }

    /** @param array<string, mixed> $payload */
    private function number(array $payload, string $field): int
    {
        $value = $payload[$field] ?? 0;

        return is_int($value) ? $value : 0;
    }
}
