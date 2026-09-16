<?php

declare(strict_types=1);

namespace BabelHub\Cli\Application;

use BabelHub\Cli\Checks\CheckAborted;
use BabelHub\Cli\Checks\CheckFinding;
use BabelHub\Cli\Checks\CheckName;
use BabelHub\Cli\Checks\CheckOutcome;
use BabelHub\Cli\Checks\CheckSeverity;
use BabelHub\Cli\Checks\FindingInterface;
use BabelHub\Cli\Checks\HardcodedStringsScanner;
use BabelHub\Cli\Checks\TranslationFinding;
use BabelHub\Cli\Config\ProjectConfig;
use BabelHub\Cli\ExitCode;
use BabelHub\Cli\Http\ApiClientInterface;
use BabelHub\Cli\Http\ApiException;
use BabelHub\Cli\Http\ApiResponse;
use BabelHub\Cli\Output\Printer;
use LogicException;

/**
 * Cztery nazwane kontrole bramki `validate` (Fala 1, pozycja 1.14) — **jedno miejsce**, w którym
 * zapada werdykt.
 *
 * Trzy rzeczy są tu zrobione wprost i każda z nich jest odpowiedzią na konkretny sposób, w jaki
 * bramka CI potrafi skłamać:
 *
 * 1. **Wynik pojawia się dopiero, gdy wszystkie kontrole się udały.** Najpierw zbieramy komplet
 *    (`collect()`), potem drukujemy. Częściowy wynik z kodem błędu byłby czymś, co skrypt weźmie
 *    za pełny — a „sprawdziłem dwie z trzech” nie jest tym samym co „sprawdziłem”.
 * 2. **Jedno żądanie na endpoint, nie na kontrolę.** `quality` i `coverage` czytają **ten sam**
 *    raport QA, więc zestaw obu kontroli to jedno `POST …/qa` — inaczej płacilibyśmy dwa razy
 *    limiterem i dwa razy czasem serwisu za policzenie dokładnie tego samego.
 * 3. **Werdykt jest per kontrola i nazwany.** Człowiek czyta `Lokalizacja nie przeszła kontroli:
 *    coverage (12 brakujących tłumaczeń), hardcoded-strings (4 ostrzeżeń)` i wie, co poprawić —
 *    bez czytania logu i bez zgadywania, którą kontrolę powtórzyć.
 *
 * Próg ważności (`--fail-on` / `checks.fail_on`) jest **parametrem**, a nie stanem: ta sama
 * kontrola może przejść przy `error` i nie przejść przy `warning`, a decyzja należy do zespołu.
 */
final readonly class CliValidate
{
    /**
     * Ile ustaleń pokazujemy w trybie `text`/`plain`.
     *
     * Duże repozytorium potrafi zwrócić setki pozycji (na tym repozytorium samo
     * `hardcoded-strings` dawało 212, bo komunikaty CLI i API są tu jeszcze nieprzetłumaczone —
     * patrz `TODO.md`). Wypisanie ich wszystkich wypycha z logu CI to, po co człowiek tam
     * przyszedł, a **liczby są zawsze pełne** — obcięta jest tylko lista, i mówimy o tym wprost.
     */
    private const PREVIEW = 30;

    public function __construct(
        private Printer $printer,
        private HardcodedStringsScanner $scanner = new HardcodedStringsScanner(),
    ) {
    }

    /**
     * @param list<CheckName> $checks kontrole do uruchomienia, w kolejności z `checks.run`
     * @param string $root katalog projektu — dla kontroli lokalnej (`hardcoded-strings`)
     * @param string|null $locale zawężenie do jednego języka (`--locale`); `null` = wszystkie
     * @param ApiClientInterface|null $client klient **tylko** dla kontroli serwisowych; `null`
     *   jest poprawne wtedy, gdy żadna z żądanych kontroli nie idzie do serwisu — dzięki temu
     *   `validate --check=hardcoded-strings` działa bez poświadczenia (i to jest jego przewaga)
     */
    public function run(
        array $checks,
        ProjectConfig $config,
        string $root,
        ?string $locale,
        CheckSeverity $threshold,
        ?ApiClientInterface $client,
    ): ExitCode {
        if ($checks === []) {
            // Bramka bez kontroli **zawsze przechodzi**, więc nie może istnieć: `checks.run: []`
            // odrzuca już walidacja konfiguracji, a to jest ten sam warunek dla kodu, który
            // złożyłby listę inaczej (np. przyszła flaga `--skip`).
            throw new LogicException('validate bez żadnej kontroli to bramka, która zawsze przechodzi.');
        }

        try {
            $outcomes = $this->collect($checks, $config, $root, $locale, $client);
        } catch (ApiException | CheckAborted $e) {
            // Dwie różne przyczyny, jedna obsługa: „nie dotarliśmy do serwisu” (`ApiException`)
            // i „serwis odmówił albo nie ma czego sprawdzić” (`CheckAborted`). Oba znaczą
            // „nie sprawdzono”, więc **nie ma prawa pojawić się kod 0 ani 6**.
            $this->printer->error($e->getMessage());

            return $e->exitCode();
        }

        foreach ($outcomes as $outcome) {
            $this->printOutcome($outcome);
        }

        // Wynik maszynowy to **jeden dokument**, niezależnie od liczby kontroli: skrypt, który
        // dopisze drugą kontrolę do `checks.run`, nie może z dnia na dzień dostać innego kształtu.
        $this->printer->json($this->document($outcomes, $threshold));

        return $this->verdict($outcomes, $threshold);
    }

    /**
     * @param list<CheckName> $checks
     *
     * @return list<CheckOutcome>
     *
     * @throws ApiException gdy nie udało się połączyć z serwisem
     * @throws CheckAborted gdy serwis odmówił albo dane wejściowe nie pozwalają sprawdzić
     */
    private function collect(
        array $checks,
        ProjectConfig $config,
        string $root,
        ?string $locale,
        ?ApiClientInterface $client,
    ): array {
        $api = null;
        $qa = null;
        $status = null;
        $outcomes = [];

        foreach ($checks as $check) {
            if (!$check->needsServer()) {
                $outcomes[] = $this->hardcodedStrings($check, $config, $root);
                continue;
            }

            // Kontrola serwisowa bez klienta to błąd programisty, nie użytkownika: komenda
            // rozwiązuje poświadczenie **wtedy, gdy o cokolwiek pyta serwisu**. `LogicException`,
            // a nie ciche `null`, bo kontrola, która nie może sprawdzić, nie ma prawa zwrócić
            // „przeszła” — a właśnie tak skończyłby się brakujący klient.
            $api ??= $client ?? throw new LogicException('Kontrola serwisowa bez klienta HTTP.');

            if ($check === CheckName::Acceptance) {
                $status ??= $this->statusReport($api, $config);
                $outcomes[] = $this->acceptance($check, $status, $locale);
                continue;
            }

            $qa ??= $this->qaReport($api, $config, $locale, $check);
            $outcomes[] = $check === CheckName::Coverage
                ? $this->coverage($check, $qa)
                : $this->quality($check, $qa);
        }

        return $outcomes;
    }

    /**
     * `quality` — cały raport QA z serwisu, z progiem ważności.
     *
     * Kontrola jest **nadzbiorem** pozostałych kontroli katalogu (zawiera też `missing`), i tak
     * zostaje celowo: `validate` bez flagi działał tak od 1.7 i zmiana tego byłaby cichym
     * rozluźnieniem bramki u wszystkich, którzy już jej używają. Kto chce węższej bramki
     * („tylko braki”), wybiera `--check=coverage`.
     */
    private function quality(CheckName $check, ApiResponse $response): CheckOutcome
    {
        $payload = $response->json();
        $findings = $this->apiFindings($payload);

        // Dosłowne ciało odpowiedzi — dlatego `json_decode` **bez** `true`: patrz `CheckOutcome`.
        $decoded = json_decode($response->body);

        return new CheckOutcome(
            name: $check,
            findings: $findings,
            summary: $this->summaryOf($payload, $findings),
            report: $decoded instanceof \stdClass ? $decoded : null,
        );
    }

    /**
     * `coverage` — brakujące tłumaczenia, czyli ustalenia QA o kodzie `missing`.
     *
     * **Własnej definicji „brakuje” tu nie ma** i to jest decyzja: kod `missing` należy do
     * słownika QA (`QualityFinding::MISSING`), a `coverage` jest jego nazwanym widokiem. Druga
     * definicja rozjechałaby się z pierwszą przy zmianie reguły po stronie serwisu — a wtedy
     * `validate` i panel mówiłyby o brakach co innego.
     */
    private function coverage(CheckName $check, ApiResponse $response): CheckOutcome
    {
        $payload = $response->json();
        $findings = [];
        $locales = [];

        foreach ($this->rows($payload) as $row) {
            if (($row['code'] ?? null) !== 'missing') {
                continue;
            }

            $finding = TranslationFinding::fromApi($row);
            $findings[] = $finding;

            if ($finding->locale !== null) {
                $locales[$finding->locale] = ($locales[$finding->locale] ?? 0) + 1;
            }
        }

        return new CheckOutcome(
            name: $check,
            findings: $findings,
            summary: ['missing' => count($findings), 'locales' => $locales],
        );
    }

    /**
     * `acceptance` — tłumaczenia, które **są**, ale nie weszły do katalogu.
     *
     * Dane pochodzą z liczników statusów (`GET …/status`), bo QA ich nie widzi: liczy ustalenia
     * wobec wartości **zatwierdzonych**, więc draft i wartość do przeglądu wyglądają tam jak brak
     * tłumaczenia. Dla człowieka to jednak dwie różne sytuacje i dwie różne osoby do zadania:
     * brak tłumaczenia to robota tłumacza, brak zatwierdzenia — recenzenta.
     *
     * Waga jest **ostrzeżeniem**, i to też jest decyzja produktowa: niezatwierdzona wartość to
     * zwykle praca w toku, a nie defekt. Zespół, który chce twardej bramki („na main nie wchodzi
     * nic niezatwierdzonego”), dopisuje `--fail-on=warning` — jawnie, w swoim CI.
     */
    private function acceptance(CheckName $check, ApiResponse $response, ?string $locale): CheckOutcome
    {
        $locales = $response->json()['locales'] ?? [];
        $locales = is_array($locales) ? $locales : [];

        if ($locale !== null && !array_key_exists($locale, $locales)) {
            throw CheckAborted::unknownLocale($check, $locale, array_map(strval(...), array_keys($locales)));
        }

        $findings = [];
        $byLocale = [];
        $draft = 0;
        $translated = 0;
        $review = 0;

        foreach ($locales as $code => $entry) {
            $code = (string) $code;
            if ($locale !== null && $code !== $locale) {
                continue;
            }

            $counts = is_array($entry) && is_array($entry['counts'] ?? null) ? $entry['counts'] : [];
            $localeDraft = $this->intAt($counts, 'DRAFT');
            $localeTranslated = $this->intAt($counts, 'TRANSLATED');
            $localeReview = $this->intAt($counts, 'REVIEW');

            $unapproved = $localeDraft + $localeTranslated + $localeReview;
            if ($unapproved === 0) {
                continue;
            }

            $findings[] = TranslationFinding::unapproved($code, $localeDraft, $localeTranslated, $localeReview);
            $byLocale[$code] = $unapproved;
            $draft += $localeDraft;
            $translated += $localeTranslated;
            $review += $localeReview;
        }

        return new CheckOutcome(
            name: $check,
            findings: $findings,
            summary: [
                'unapproved' => $draft + $translated + $review,
                'draft' => $draft,
                'translated' => $translated,
                'review' => $review,
                'locales' => $byLocale,
            ],
        );
    }

    /** @return CheckOutcome */
    private function hardcodedStrings(CheckName $check, ProjectConfig $config, string $root): CheckOutcome
    {
        // `CliIoException` (nie ma katalogu) i `CliConfigException` lecą wyżej — `Application`
        // mapuje je na kody `1` i `2`, bo to awaria narzędzia i błąd konfiguracji, a nie wynik
        // kontroli. Łapanie ich tutaj znaczyłoby przepisanie tej tabeli drugi raz.
        $findings = $this->scanner->scan($root, $config->scanPaths, $config->checksIgnore);

        return new CheckOutcome(
            name: $check,
            findings: $findings,
            summary: [
                'errors' => $this->countOf($findings, CheckSeverity::Error),
                'warnings' => $this->countOf($findings, CheckSeverity::Warning),
                'total' => count($findings),
                'files' => count(array_unique(array_map(
                    static fn (CheckFinding $finding): string => $finding->file,
                    $findings,
                ))),
            ],
        );
    }

    private function qaReport(
        ApiClientInterface $client,
        ProjectConfig $config,
        ?string $locale,
        CheckName $check,
    ): ApiResponse {
        $body = [];
        if ($locale !== null) {
            $body['locale'] = $locale;
        }

        // `POST`, nie `GET`: to **obliczenie** raportu, a nie odczyt zasobu, którego może dotknąć
        // prefetch albo cache pośrednika.
        $response = $client->post('/api/v1/projects/' . rawurlencode($config->project) . '/qa', $body);

        if (!$response->isSuccess()) {
            // Nazwa kontroli, którą naprawdę uruchomiono: `--check=coverage` nie może dostać
            // komunikatu „Kontrola quality: …”, bo człowiek szukałby wtedy nie tej kontroli.
            throw CheckAborted::fromResponse($check, $response);
        }

        return $response;
    }

    private function statusReport(ApiClientInterface $client, ProjectConfig $config): ApiResponse
    {
        // Bez `?locale=`: w `status` zawęża ono **ustalenia QA**, a nie listę języków, więc
        // wysłanie go tutaj sugerowałoby, że zmienia coś w licznikach. Zawężenie robimy lokalnie.
        $response = $client->get('/api/v1/projects/' . rawurlencode($config->project) . '/status');

        if (!$response->isSuccess()) {
            throw CheckAborted::fromResponse(CheckName::Acceptance, $response);
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<TranslationFinding>
     */
    private function apiFindings(array $payload): array
    {
        $findings = [];
        foreach ($this->rows($payload) as $row) {
            $findings[] = TranslationFinding::fromApi($row);
        }

        return $findings;
    }

    /**
     * Ustalenia z ciała odpowiedzi, w kolejności z serwisu.
     *
     * @param array<string, mixed> $payload
     *
     * @return list<array<string, mixed>>
     */
    private function rows(array $payload): array
    {
        $rows = $payload['findings'] ?? [];
        if (!is_array($rows)) {
            return [];
        }

        $found = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            // Ustalenie jest **obiektem** w JSON-ie, więc klucze są napisami; tablica o kluczach
            // liczbowych nie może się tu pojawić (a gdyby się pojawiła, `fromApi` i tak czyta
            // wyłącznie znane pola).
            /** @var array<string, mixed> $row */
            $found[] = $row;
        }

        return $found;
    }

    /**
     * Liczniki QA: bierzemy je z serwisu, a gdy ich nie ma — liczymy sami z ustaleń.
     *
     * Wartości z serwisu są źródłem prawdy (to on wie, ile ustaleń pominął przez limity),
     * ale brak `summary` w odpowiedzi nie może zrobić z podsumowania zera.
     *
     * @param array<string, mixed> $payload
     * @param list<FindingInterface> $findings
     *
     * @return array<string, mixed>
     */
    private function summaryOf(array $payload, array $findings): array
    {
        $summary = $payload['summary'] ?? null;
        if (is_array($summary) && $summary !== []) {
            return $summary;
        }

        return [
            'errors' => $this->countOf($findings, CheckSeverity::Error),
            'warnings' => $this->countOf($findings, CheckSeverity::Warning),
            'info' => $this->countOf($findings, CheckSeverity::Info),
            'total' => count($findings),
        ];
    }

    /** @param list<FindingInterface> $findings */
    private function countOf(array $findings, CheckSeverity $severity): int
    {
        return count(array_filter(
            $findings,
            static fn (FindingInterface $finding): bool => $finding->severity() === $severity,
        ));
    }

    /** @param array<string, mixed> $counts */
    private function intAt(array $counts, string $key): int
    {
        $value = $counts[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Wydruk jednej kontroli: ustalenia (do `PREVIEW`), informacja o obcięciu i zdanie podsumowania.
     *
     * Linia ustalenia ma kształt `WAGA kod miejsce — zdanie`, więc log CI filtruje się bez
     * parsowania JSON-a; podsumowanie idzie na `stdout` także w `plain`, bo to **wynik**.
     */
    private function printOutcome(CheckOutcome $outcome): void
    {
        foreach (array_slice($outcome->findings, 0, self::PREVIEW) as $finding) {
            $this->printer->plain(sprintf(
                '%s %s %s — %s',
                strtoupper($finding->severity()->value),
                $finding->code(),
                $finding->location(),
                $finding->message(),
            ));
        }

        if (count($outcome->findings) > self::PREVIEW) {
            // `plain`, a nie `result`: obcięcie listy jest częścią wyniku — bez tego zdania
            // czytelnik logu w trybie `plain` nie wie, że czegoś nie widzi.
            $this->printer->plain(sprintf(
                '… i %d więcej (pełna lista: --output=json)',
                count($outcome->findings) - self::PREVIEW,
            ));
        }

        $this->printer->plain($outcome->summaryLine());
    }

    /**
     * @param list<CheckOutcome> $outcomes
     *
     * @return array<string, mixed>
     */
    private function document(array $outcomes, CheckSeverity $threshold): array
    {
        $checks = [];
        $failures = [];

        foreach ($outcomes as $outcome) {
            $checks[$outcome->name->value] = $outcome->toArray($threshold);
            if (!$outcome->pass($threshold)) {
                $failures[] = $outcome->name->value;
            }
        }

        return [
            // Mapa `nazwa => dokument`, a nie lista: pytania brzmią „czy coverage przeszło?”
            // i „pokaż ustalenia jakości”, a nie „co było pierwsze”.
            'checks' => $checks,
            'pass' => $failures === [],
            // Próg jest w dokumencie, bo bez niego `pass: false` przy samych ostrzeżeniach
            // wygląda na sprzeczność (i wymaga zgadywania, jakim progiem liczyć `blocking`).
            'fail_on' => $threshold->value,
            'failures' => $failures,
        ];
    }

    /** @param list<CheckOutcome> $outcomes */
    private function verdict(array $outcomes, CheckSeverity $threshold): ExitCode
    {
        $blocked = array_values(array_filter(
            $outcomes,
            static fn (CheckOutcome $outcome): bool => !$outcome->pass($threshold),
        ));

        if ($blocked === []) {
            $this->printer->info('Lokalizacja przeszła kontrolę.');

            return ExitCode::Success;
        }

        $phrases = array_map(
            static fn (CheckOutcome $outcome): string => $outcome->blockingPhrase($threshold),
            $blocked,
        );

        $this->printer->error(sprintf('Lokalizacja nie przeszła kontroli: %s.', implode(', ', $phrases)));

        if ($threshold === CheckSeverity::Warning) {
            // Bez tego zdania komunikat nie mówi, **dlaczego** ostrzeżenie zatrzymało build —
            // a to jest jedyna różnica między tą bramką a domyślną.
            $this->printer->error(
                'Zadziałał próg --fail-on=warning: przy domyślnym progu te ustalenia nie zatrzymują merge\'a.',
            );
        }

        // Kod `6`, nie `1`: to **wynik kontroli**, a nie awaria narzędzia. Pipeline ma wiedzieć,
        // że problem jest w tłumaczeniach, a nie w konfiguracji czy połączeniu.
        return ExitCode::CheckFailed;
    }
}
