<?php

declare(strict_types=1);

namespace BabelHub\Cli\Application;

use BabelHub\Cli\Config\CatalogLock;
use BabelHub\Cli\Config\CatalogSource;
use BabelHub\Cli\Config\CatalogLockStore;
use BabelHub\Cli\Config\ProjectConfig;
use BabelHub\Cli\CliIoException;
use BabelHub\Cli\ExitCode;
use BabelHub\Cli\Files\LocaleFile;
use BabelHub\Cli\Http\ApiClientInterface;
use BabelHub\Cli\Output\Printer;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * `babelhub pull` — katalogi z serwisu do plików w repozytorium, **bez szumu w diffie**.
 *
 * Trzy rzeczy, które ten kod robi świadomie i które są całą jego wartością:
 *
 * 1. **Nie dotyka pliku, gdy katalog się nie zmienił.** Zapytanie idzie z `If-None-Match`
 *    z wersją zapisaną w `.babelhub.lock.json`, więc odpowiedź to `304` i plik zostaje
 *    nietknięty. Bez tego `pull` w CI po każdym commicie przepisywałby wszystkie pliki
 *    (choćby kolejnością kluczy) i code review tonęłoby w diffach z niczego.
 * 2. **Pisze atomowo.** Plik tymczasowy + `rename`, więc czytelnik (aplikacja, `git`, edytor)
 *    widzi albo starą treść, albo nową — nigdy połowę. Przerwany zapis w pliku językowym to
 *    uszkodzenie, które trafia do commita, bo „plik się zmienił”.
 * 3. **Nie zapisuje pustego katalogu.** Gdy serwis nie ma ani jednego zatwierdzonego tekstu
 *    w języku, zapis pustej tablicy **skasowałby** pracę, która jeszcze nie została zatwierdzona
 *    (albo teksty przygotowane lokalnie). Pusty katalog to „nie ma czego pobierać”, a nie
 *    „pusty plik”.
 *
 * Do tego **hook projektu** (`download.post_process_command`, pozycja 1.19): po zapisaniu plików
 * `pull` oddaje sterowanie komendzie z repozytorium, żeby formatowanie dociągnął łańcuch
 * budowania klienta — a nie każdy pipeline z osobna.
 *
 * `--check` to tryb, w którym nic nie jest zapisywane: raportuje rozjazd między lockiem
 * a serwisem i kończy się kodem `6`. Właśnie tego używa CI, żeby odpowiedzieć na pytanie
 * „czy to, co mam w repozytorium, odpowiada temu, co jest w serwisie”.
 */
final readonly class CliPull
{
    /**
     * Ile znaków wyjścia hook-a pokazujemy przy błędzie.
     *
     * Log formatera bywa długi, a komunikat o błędzie ma zostać czytelny — bez obcięcia
     * wiadomość „post-process nie powiódł się” utonęłaby w setkach linii jego wyjścia.
     */
    private const POST_PROCESS_OUTPUT_LIMIT = 2000;

    public function __construct(
        private ApiClientInterface $client,
        private Printer $printer,
        private PostProcessRunner $postProcessRunner = new PostProcessRunner(),
    ) {
    }

    /**
     * @param list<string> $onlyLocales języki, które wolno pobrać (puste = wszystkie języki projektu).
     *   Kolejność źródeł jest ustalona w `PullCommand`: flaga `--locale` → `download.locales`
     *   z konfiguracji → wszystkie języki projektu.
     */
    public function run(
        ProjectConfig $config,
        string $root,
        bool $checkOnly = false,
        array $onlyLocales = [],
    ): ExitCode {
        $projectPath = '/api/v1/projects/' . rawurlencode($config->project);

        $projectResponse = $this->client->get($projectPath);
        if (!$projectResponse->isSuccess()) {
            $this->printer->error($projectResponse->message());

            return $projectResponse->exitCode();
        }

        $project = $projectResponse->json()['project'] ?? [];
        $available = [];
        foreach ((array) (is_array($project) ? ($project['locales'] ?? []) : []) as $code) {
            if (is_string($code) && $code !== '') {
                $available[] = $code;
            }
        }

        if ($available === []) {
            $this->printer->error(sprintf(
                'Projekt %s nie ma przypisanych języków. Dodaj je w panelu (Aplikacja → Języki projektu).',
                $config->project,
            ));

            return ExitCode::Usage;
        }

        // Wybrany język, którego projekt **nie prowadzi**, to błąd użycia, a nie cicho pominięta
        // pozycja: `download.locales: [de_DE]` w projekcie bez `de_DE` znaczyłoby, że `pull`
        // nie robi nic i wygląda na sukces — czyli najgorszy możliwy kształt pomyłki w konfiguracji.
        $locales = $onlyLocales === [] ? $available : array_values(array_intersect($onlyLocales, $available));
        if ($locales === []) {
            $this->printer->error(sprintf(
                'Żaden z wybranych języków (%s) nie należy do projektu %s (prowadzi: %s).',
                implode(', ', $onlyLocales),
                $config->project,
                implode(', ', $available),
            ));

            return ExitCode::Usage;
        }

        $identityResponse = $this->client->get('/api/v1/me');
        if (!$identityResponse->isSuccess()) {
            $this->printer->error($identityResponse->message());
            return $identityResponse->exitCode();
        }
        $organization = $identityResponse->json()['organization'] ?? null;
        $organizationSlug = is_array($organization) ? ($organization['slug'] ?? null) : null;
        if (!is_string($organizationSlug) || trim($organizationSlug) === '') {
            $this->printer->error('Serwis nie potwierdził organizacji źródła katalogów. Lock nie został zmieniony.');
            return ExitCode::Failure;
        }
        try {
            $source = new CatalogSource($this->client->baseUrl(), $organizationSlug);
        } catch (InvalidArgumentException $error) {
            $this->printer->error($error->getMessage());
            return ExitCode::Usage;
        }
        $lockStore = new CatalogLockStore($root);
        $lock = $lockStore->load();
        // Legacy locks have no trustworthy server/tenant identity. Their versions,
        // counts and timestamps cannot be reused for the current source.
        if ($lock !== null && !$lock->belongsTo($config->project, $source)) {
            $lock = null;
        }
        $updated = new CatalogLock($config->project, [], $source);

        $outcomes = [];
        $drifted = false;
        $failure = null;
        $written = 0;
        $file = new LocaleFile($config->fileFormat);
        $now = (new DateTimeImmutable())->format(DATE_ATOM);

        foreach ($locales as $locale) {
            $known = $lock?->versionFor($locale);
            $headers = $known !== null ? ['If-None-Match' => '"' . $known . '"'] : [];

            $response = $this->client->get($projectPath . '/translations/' . rawurlencode($locale), [], $headers);

            if ($response->status === 304) {
                if ($known === null) {
                    $this->printer->error(sprintf('%s: serwis zwrócił 304 bez warunku dla zaufanego locka.', $locale));
                    $outcomes[$locale] = ['outcome' => PullOutcome::Failed->value, 'version' => null, 'keys' => 0];
                    $failure = ExitCode::Failure;
                    break;
                }
                // Nic się nie zmieniło: **nie piszemy pliku i przepisujemy wersję do nowego locka**.
                // Liczbę kluczy bierzemy z poprzedniego locka — serwis nie przysłał treści,
                // więc nie mamy skąd jej wziąć, a wpisanie zera mówiłoby o katalogu nieprawdę.
                $keys = $lock?->keysFor($locale) ?? 0;
                $outcomes[$locale] = ['outcome' => PullOutcome::Skipped->value, 'version' => $known, 'keys' => $keys];
                // Datę bierzemy z **poprzedniego** locka, nie z `$now`: nic nie zapisano, więc
                // `pulled_at` nie ma prawa się ruszyć. Inaczej lock różni się przy każdym
                // przebiegu i `sync` w CI commituje samą datę — w kółko (patrz `pulledAtFor`).
                $updated = $updated->withLocale($locale, $known, $keys, $lock?->pulledAtFor($locale) ?? '');
                continue;
            }

            if (!$response->isSuccess()) {
                $this->printer->error(sprintf('%s: %s', $locale, $response->message()));
                $outcomes[$locale] = ['outcome' => PullOutcome::Failed->value, 'version' => null, 'keys' => 0];
                $failure = $response->exitCode();

                // **Przerywamy pętlę, ale nie raport** — i to jest cała różnica wobec cichego
                // `return`: języki przetworzone wcześniej mają już zapisane pliki, więc bez
                // raportu użytkownik nie wie, co właściwie zostało zmienione. Lock **nie** jest
                // wtedy zapisywany (nie opisuje stanu, którego nie ma), a komunikat mówi wprost,
                // że pliki mogły się zmienić i co zrobić dalej.
                break;
            }

            $payload = $response->json();
            $messages = [];
            foreach ((array) ($payload['translations'] ?? []) as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    $messages[$key] = $value;
                }
            }

            $version = is_int($payload['version'] ?? null) ? $payload['version'] : 0;

            if ($messages === []) {
                // Pusty katalog w trybie `--check` **jest rozjazdem**, gdy lock mówi o innej
                // wersji: `pull` przepisałby wtedy lock na `$version`, a repozytorium zostałoby
                // z plikami ze starej wersji. Wcześniej ta gałąź `continue`-owała przed
                // sprawdzeniem `$checkOnly`, więc bramka CI mówiła „zsynchronizowane”
                // o repozytorium, które `pull` jeszcze by zmienił.
                if ($checkOnly && $known !== $version) {
                    $drifted = true;
                    $outcomes[$locale] = ['outcome' => PullOutcome::Drifted->value, 'version' => $version, 'keys' => 0];
                    continue;
                }

                $outcomes[$locale] = ['outcome' => PullOutcome::Empty->value, 'version' => $version, 'keys' => 0];
                // Pusty katalog **nie kasuje pliku**, więc i tu nic nie zapisano: `pulled_at`
                // zostaje z poprzedniego locka, a zmienia się tylko wersja (ta jedzie w `If-None-Match`).
                $updated = $updated->withLocale($locale, $version, 0, $lock?->pulledAtFor($locale) ?? '');
                continue;
            }

            if ($checkOnly) {
                // Rozjazd: lock (albo brak locka) mówi „mam wersję X”, a serwis daje nowszą treść.
                $drifted = true;
                $outcomes[$locale] = ['outcome' => PullOutcome::Drifted->value, 'version' => $version, 'keys' => count($messages)];
                continue;
            }

            $path = $config->fileFor($locale, $root);

            // `{ns}` w `files.pattern` rozdziela katalog na pliki po pierwszym segmencie klucza
            // („przestrzeń nazw” w rozumieniu i18next). Każdy plik zapisujemy **osobno i tymi
            // samymi opcjami**, bo styl bierze się z każdego z nich z osobna: projekt może mieć
            // `common.json` z dwoma spacjami i `admin.json` z czterema i to nie jest nasza sprawa.
            foreach ($this->byNamespace($messages, $config->usesNamespaces()) as $group) {
                $file->write(
                    $group['namespace'] === null ? $path : $config->fileFor($locale, $root, $group['namespace']),
                    $group['messages'],
                    $config->writeOptions(),
                    $locale,
                );
                $written++;
            }

            $outcomes[$locale] = ['outcome' => PullOutcome::Written->value, 'version' => $version, 'keys' => count($messages)];
            $updated = $updated->withLocale($locale, $version, count($messages), $now);
        }

        if (!$checkOnly && $failure === null) {
            // Lock zapisujemy także wtedy, gdy nic nie zapisano: on opisuje **stan repozytorium**,
            // więc po pierwszym `pull` musi istnieć, żeby następny mógł zadać `If-None-Match`.
            // Po niepowodzeniu **nie zapisujemy go wcale** — lock, który opisuje tylko część
            // języków, kłamałby o repozytorium, a `--check` słusznie zgłosi wtedy rozjazd.
            $lockStore->save($updated);
        }

        // Hook projektu uruchamiamy **tylko wtedy, gdy coś naprawdę zapisano**: przy samych
        // `304` nie ma czego formatować, a uruchamianie `prettier`-a po to, żeby przepisał pliki
        // własnym stylem, to dokładnie ten diff z niczego, którego cała ta komenda unika.
        $postProcess = null;
        if (!$checkOnly && $failure === null && $written > 0 && $config->downloadPostProcessCommand !== null) {
            $postProcess = $this->postProcess($config->downloadPostProcessCommand, $root);
            if ($postProcess['failed']) {
                $this->printer->json([
                    'project' => $config->project,
                    'check_only' => $checkOnly,
                    'lock_path' => $lockStore->path(),
                    'locales' => $outcomes,
                    'drifted' => $drifted,
                    'post_process' => $postProcess['payload'],
                ]);

                return ExitCode::Failure;
            }
        }

        $this->printer->json([
            'project' => $config->project,
            'check_only' => $checkOnly,
            'lock_path' => $lockStore->path(),
            'locales' => $outcomes,
            'drifted' => $drifted,
            // Pole jest zawsze obecne (`null` = brak hook-a), bo klient czytający wynik musi
            // umieć odróżnić „nie skonfigurowano” od „pole zniknęło z kontraktu”.
            'post_process' => $postProcess['payload'] ?? null,
        ]);

        foreach ($outcomes as $locale => $outcome) {
            $this->printer->result(sprintf(
                '%-8s %s',
                $locale,
                $this->describe($outcome['outcome'], $outcome['keys'], $outcome['version']),
            ));
        }

        if ($failure !== null) {
            // Raport **przed** zwróceniem kodu: część języków została już zapisana i użytkownik
            // musi to wiedzieć. Lock nie został zaktualizowany, więc `pull --check` zgłosi
            // rozjazd — i słusznie, bo lock przestaje opisywać repozytorium.
            $this->printer->error(
                'Nie dokończyłem pobierania: część plików mogła zostać zapisana, a .babelhub.lock.json NIE został zaktualizowany. ' .
                'Usuń przyczynę i uruchom `babelhub pull` ponownie.'
            );

            return $failure;
        }

        if ($checkOnly && $drifted) {
            $this->printer->error(
                'Repozytorium nie odpowiada serwisowi: katalog zmienił się od ostatniego pull. Uruchom `babelhub pull` i zacommituj pliki.'
            );

            return ExitCode::CheckFailed;
        }

        $this->printer->info($checkOnly
            ? 'Sprawdzone — katalogi w repozytorium odpowiadają wersjom z serwisu.'
            : sprintf('Zapisane. Wersje katalogów: %s', $lockStore->path()));

        return ExitCode::Success;
    }

    /**
     * Katalog podzielony na pliki: `namespace: null` => jeden plik ze wszystkim, `„nav”` => `nav.*`.
     *
     * Przestrzenią nazw jest **pierwszy segment klucza przed kropką** — tak rozumie je i18next
     * i tak trzymają je projekty, dla których `{ns}` w ogóle istnieje. Klucz bez kropki (nasz
     * katalog takiego nie tworzy, ale plik klienta może go mieć) trafia do pliku o własnej nazwie:
     * wymyślanie dla niego wspólnej przestrzeni (`default`, `translation`) byłoby zgadywaniem,
     * a katalogowanie go razem z innymi kluczami — cichym mieszaniem dwóch porządków.
     *
     * Zwracamy **listę par**, a nie mapę `nazwa => klucze`: `null` jako klucz tablicy PHP staje
     * się pustym napisem, więc „brak przestrzeni nazw” i „przestrzeń nazw o pustej nazwie” byłyby
     * nierozróżnialne — a to właśnie na tej różnicy stoi cała gałąź bez `{ns}`.
     *
     * @param array<string, string> $messages
     *
     * @return list<array{namespace: string|null, messages: array<string, string>}>
     */
    private function byNamespace(array $messages, bool $split): array
    {
        if (!$split) {
            return [['namespace' => null, 'messages' => $messages]];
        }

        /** @var array<string, array<string, string>> $groups */
        $groups = [];
        foreach ($messages as $key => $value) {
            $namespace = str_contains($key, '.') ? (string) strstr($key, '.', true) : $key;
            $groups[$namespace][$key] = $value;
        }

        $result = [];
        foreach ($groups as $namespace => $group) {
            $result[] = ['namespace' => (string) $namespace, 'messages' => $group];
        }

        return $result;
    }

    /**
     * Uruchomienie hook-a projektu po zapisaniu plików (1.19).
     *
     * Trzy rzeczy, które muszą być prawdą, żeby ten hook dawał się w ogóle używać w CI:
     *
     * 1. **Pliki są już zapisane i lock już opisuje nowy stan.** Hook jest krokiem *po* `pull`,
     *    więc jego niepowodzenie nie może wyglądać jak nieudane pobranie — komunikat mówi wprost,
     *    co jest w repozytorium, a kod wyjścia (`1`) znaczy „narzędzie nie dokończyło pracy”.
     * 2. **Kod wyjścia hook-a jest widoczny.** `124` przy przekroczeniu limitu to konwencja
     *    `timeout(1)`, więc rozróżnienie „skrypt zwrócił błąd” od „skrypt wisiał” nie wymaga
     *    czytania dokumentacji.
     * 3. **Wyjście hook-a pokazujemy tylko przy błędzie.** Przy sukcesie jego efektem są pliki,
     *    a log `pull` w CI ma zostać czytelny; przy błędzie to jedyne miejsce z powodem.
     *
     * @return array{failed: bool, payload: array<string, mixed>}
     */
    private function postProcess(string $command, string $root): array
    {
        $this->printer->info(sprintf('Post-process: %s', $command));

        try {
            $result = $this->postProcessRunner->run($command, $root);
        } catch (CliIoException $e) {
            // Brak `proc_open` albo procesu nie da się uruchomić: pliki są zapisane, ale hook
            // **nie wykonał się wcale** — a to jest inny stan niż „wykonał się i zwrócił błąd”.
            $this->printer->error($e->getMessage());

            return ['failed' => true, 'payload' => ['command' => $command, 'ran' => false, 'exit_code' => null]];
        }

        $payload = [
            'command' => $command,
            'ran' => true,
            'exit_code' => $result['exit_code'],
            'timed_out' => $result['exit_code'] === 124,
        ];

        if ($result['exit_code'] === 0) {
            return ['failed' => false, 'payload' => $payload];
        }

        $this->printer->error($result['exit_code'] === 124
            ? sprintf(
                'Post-process „%s” przekroczył limit %d s i został zatrzymany. Pliki są zapisane, ale NIE przetworzone.',
                $command,
                $this->postProcessRunner->timeoutSeconds(),
            )
            : sprintf(
                'Post-process „%s” zwrócił kod %d. Pliki są zapisane i lock jest aktualny, ale NIE zostały przetworzone.',
                $command,
                $result['exit_code'],
            ));

        if ($result['output'] !== '') {
            // Wyjście hook-a bywa długie (log formatera) — obcinamy je z jawnym ogonem,
            // żeby komunikat o błędzie nie zakrył sedna.
            $this->printer->error(mb_substr($result['output'], 0, self::POST_PROCESS_OUTPUT_LIMIT));
        }

        return ['failed' => true, 'payload' => $payload];
    }

    private function describe(string $outcome, int $keys, ?int $version): string
    {
        return match ($outcome) {
            PullOutcome::Written->value => sprintf('zapisane (%d kluczy, wersja %d)', $keys, $version ?? 0),
            PullOutcome::Skipped->value => sprintf('bez zmian (wersja %d)', $version ?? 0),
            PullOutcome::Empty->value => 'brak zatwierdzonych tekstów — plik nietknięty',
            PullOutcome::Drifted->value => sprintf('rozjazd: serwis ma wersję %d (%d kluczy)', $version ?? 0, $keys),
            default => 'błąd',
        };
    }
}
