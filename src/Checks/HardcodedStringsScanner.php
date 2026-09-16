<?php

declare(strict_types=1);

namespace BabelHub\Cli\Checks;

use BabelHub\Cli\Scan\SourceFiles;

/**
 * Kontrola „stringi zakodowane na sztywno” (Fala 1, pozycja 1.13) — **heurystyka, nie parser**.
 *
 * Problem, który rozwiązuje: klucz dodany w kodzie trafia do serwisu przez `push`, ale tekst
 * wpisany wprost w szablonie (`<h1>Welcome back</h1>`) nie trafia nigdzie. To jedyna kontrola,
 * która działa **u źródła** — reszta (brakujące, niezatwierdzone, placeholdery) pilnuje już
 * istniejących tłumaczeń, więc tekstu, którego nikt nie wyniósł do katalogu, nie widzi żadna.
 *
 * **Dlaczego heurystyka i dlaczego konserwatywna.** Rozpoznanie „to jest tekst dla człowieka”
 * wymagałoby typu zmiennej i kontekstu renderowania, a my mamy napisy w plikach. Dlatego
 * zgłaszamy tylko rzeczy, które **wyglądają na zdanie**: mają spację, co najmniej kilka liter,
 * zaczynają się wielką literą i nie są żadnym z technicznych kształtów (ścieżka, URL, klucz,
 * fragment kodu, nazwa nagłówka HTTP, format daty, zapytanie SQL). Fałszywy alarm jest tu
 * kosztowniejszy niż brak trafienia: pierwszy uczy ludzi ignorować kontrolę, drugi zostawia
 * stan, który i tak jest widoczny w panelu („ten język nie jest kompletny”).
 *
 * **Co świadomie zostaje poza kontrolą:**
 *
 * * teksty już tłumaczone (`trans(…)`, `|trans`, `{% trans %}`) — to nie jest problem;
 * * komentarze (PHP liniowe i blokowe; Twig `{# #}`) — nie trafiają do interfejsu;
 * * napisy jednosylabowe i pojedyncze słowa (`Save`, `Locales`) — nie do odróżnienia od
 *   identyfikatora, nazwy statusu czy etykiety technicznej;
 * * teksty w `assert()`/`throw new …` — komunikaty dla developera, nie dla użytkownika.
 *
 * Kontrola **nie wymaga poświadczenia ani serwera**: czy tekst jest w kodzie, wie tylko kod.
 * Dzięki temu działa w CI także przed logowaniem (i to jest jej przewaga nad pozostałymi).
 */
final readonly class HardcodedStringsScanner
{
    public const CODE = 'hardcoded_string';

    /** Krótsze napisy to prawie zawsze identyfikatory, a nie zdania. */
    private const MIN_LENGTH = 10;

    /** Ile liter musi być w napisie, żeby mówić o zdaniu (odcina „v1.2 beta” i daty). */
    private const MIN_LETTERS = 6;

    /** Techniczne kształty — każdy z nich ktoś kiedyś zobaczyłby jako fałszywy alarm. */
    private const TECHNICAL_PATTERNS = [
        '/:\/\//',                      // URL
        '/^https?:/i',
        '/::/',                         // Class::method
        '/->/',                         // $foo->bar
        '/\$\{?[a-zA-Z_]/',             // interpolacja
        '/\\\\[a-zA-Z]/',               // escape/ścieżka Windows
        '/\bSELECT\b|\bINSERT\b|\bUPDATE\b|\bDELETE\b|\bWHERE\b/i',
        '/^\s*[%{]/',                   // format/placeholder na początku
        '/^[A-Z][a-zA-Z]*-[A-Za-z]+:\s/',  // Content-Type: …, X-RateLimit-Limit: … (myślnik = nagłówek HTTP)
        '/^[A-Z][a-zA-Z-]*:[A-Z]/',         // Content-Type:application/json (bez spacji)
        '/\b\d{4}-\d{2}-\d{2}\b/',      // data
        '/\.(php|twig|json|css|js|html|yaml|yml)\b/i',
        '/\b(utf-8|iso-\d{4}|sha256|sha-1|md5|argon2|base64)\b/i',
        '/\b(monolog|doctrine|symfony|twig|slim|composer)\b/i', // nazwy pakietów w zdaniach technicznych
    ];

    /** Miejsca, w których napis jest komunikatem dla developera, a nie tekstem dla użytkownika. */
    private const DEVELOPER_CONTEXTS = [
        '/\bthrow\s+new\b/',
        '/\bassert\s*\(/',
        '/\btrigger_error\s*\(/',
        '/\bsprintf\s*\(/',
        '/\berror_log\s*\(/',
    ];

    public function __construct(private SourceFiles $files = new SourceFiles())
    {
    }

    /**
     * @param string $root korzeń projektu
     * @param list<string> $paths katalogi do przeskanowania
     * @param list<string> $ignore wzorce ścieżek do pominięcia (dokładna ścieżka albo prefiks katalogu)
     *
     * @return list<CheckFinding>
     */
    public function scan(string $root, array $paths, array $ignore = []): array
    {
        $findings = [];

        foreach ($this->files->in($root, $paths) as $file) {
            if ($this->ignored($file, $ignore)) {
                continue;
            }

            $contents = file_get_contents($root . '/' . $file);
            if ($contents === false || $contents === '') {
                continue;
            }

            $findings = [...$findings, ...$this->inFile($file, $contents)];
        }

        return $findings;
    }

    /** @param list<string> $ignore */
    private function ignored(string $file, array $ignore): bool
    {
        foreach ($ignore as $pattern) {
            $pattern = trim($pattern, '/');
            if ($pattern === '') {
                continue;
            }

            if ($file === $pattern || str_starts_with($file, rtrim($pattern, '/') . '/')) {
                return true;
            }
        }

        return false;
    }

    /** @return list<CheckFinding> */
    private function inFile(string $file, string $contents): array
    {
        return str_ends_with($file, '.twig')
            ? $this->inTwig($file, $contents)
            : $this->inPhp($file, $contents);
    }

    /** @return list<CheckFinding> */
    private function inPhp(string $file, string $contents): array
    {
        $findings = [];
        $code = $this->withoutPhpComments($contents);

        foreach (explode("\n", $code) as $index => $line) {
            $lineNumber = $index + 1;

            // Linia z tłumaczeniem nie jest problemem — reszta literałów w niej też nie,
            // bo w praktyce to argumenty tej samej funkcji (parametry, kontekst).
            if (preg_match('/\btrans\s*\(|\|\s*trans\b/', $line) === 1) {
                continue;
            }

            if ($this->isDeveloperContext($line)) {
                continue;
            }

            $matches = [];
            if (
                preg_match_all(
                    '/\'([^\'\\\\]{4,})\'|"([^"\\\\]{4,})"/',
                    $line,
                    $matches,
                    PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL,
                ) === false
            ) {
                continue;
            }

            foreach ($matches as $match) {
                // `PREG_UNMATCHED_AS_NULL`: niedopasowana gałąź alternatywy jest `null`, a nie
                // brakującym kluczem — dzięki temu czytamy obie bez zgadywania, która istnieje.
                $text = $match[1] ?? $match[2] ?? null;
                if (!is_string($text) || !$this->looksLikeUserFacingText($text)) {
                    continue;
                }

                $findings[] = $this->finding($file, $lineNumber, $text);
            }
        }

        return $findings;
    }

    /** @return list<CheckFinding> */
    private function inTwig(string $file, string $contents): array
    {
        $findings = [];

        // Komentarze zamieniamy na puste linie (a nie usuwamy), żeby **numery linii się nie
        // przesunęły**: wskazanie złej linii jest gorsze niż brak wskazania, bo człowiek idzie
        // do pliku, nic nie znajduje i przestaje ufać kontroli. Pierwsza wersja tej metody
        // usuwała tagi z całego pliku i numerowała wynik — raportowała `dashboard/index.twig:233`
        // w pliku, który ma 150 linii.
        $withoutComments = preg_replace_callback(
            '/\{#.*?#\}/s',
            static fn (array $m): string => str_repeat("\n", substr_count($m[0], "\n")),
            $contents,
        ) ?? $contents;

        foreach (explode("\n", $withoutComments) as $index => $line) {
            // Kolejność jest istotna: najpierw bloki kodu (w nich „tekst” bywa kodem), a potem
            // to, co zostanie, jest treścią szablonu — czyli tym, co widzi użytkownik.
            $text = preg_replace('/\{\{.*?\}\}|\{%.*?%\}/', ' ', $line) ?? $line;
            $text = preg_replace('/<[^>]*>/', ' ', $text) ?? $text;
            $candidate = trim((string) preg_replace('/\s+/u', ' ', $text));

            if ($candidate === '' || !$this->looksLikeUserFacingText($candidate)) {
                continue;
            }

            $findings[] = $this->finding($file, $index + 1, $candidate);
        }

        return $findings;
    }

    /**
     * Czy napis wygląda na tekst dla człowieka — **cztery warunki i lista kształtów technicznych**.
     *
     * Wszystkie warunki są pozytywne (`musi mieć…`), a nie negatywne (`nie może być…`), bo lista
     * rzeczy, którymi tekst nie jest, nie ma końca. To ta sama decyzja co przy skanie kluczy:
     * wolimy powiedzieć „te 12 pozycji wygląda na tekst w kodzie” niż udawać kompletność.
     */
    public function looksLikeUserFacingText(string $text): bool
    {
        $trimmed = trim($text);

        if (mb_strlen($trimmed) < self::MIN_LENGTH) {
            return false;
        }

        // Zdanie ma spację; identyfikator, ścieżka i nazwa klasy nie.
        if (!str_contains($trimmed, ' ')) {
            return false;
        }

        $letters = preg_replace('/[^\p{L}]/u', '', $trimmed) ?? '';
        if (mb_strlen($letters) < self::MIN_LETTERS) {
            return false;
        }

        // Wielka litera na początku: odcina komunikaty logów („could not connect to…”) i klucze.
        if (preg_match('/^\p{Lu}/u', $trimmed) !== 1) {
            return false;
        }

        foreach (self::TECHNICAL_PATTERNS as $pattern) {
            if (preg_match($pattern, $trimmed) === 1) {
                return false;
            }
        }

        // Klucz tłumaczenia to nie tekst (`about.us.title`) — nawet jeśli ma kropki i podkreślenia.
        if (preg_match('/^[a-z][a-zA-Z0-9_]*(?:\.[a-z][a-zA-Z0-9_]*)+$/', $trimmed) === 1) {
            return false;
        }

        return true;
    }

    private function isDeveloperContext(string $line): bool
    {
        foreach (self::DEVELOPER_CONTEXTS as $pattern) {
            if (preg_match($pattern, $line) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Komentarze PHP bez śledzenia kontekstu PHP-a w HTML-u — świadomie.
     *
     * Szablon z osadzonym PHP (`<?php // … ?>`) traci w ten sposób kilka linii, które i tak nie
     * byłyby kandydatami, a pełny lekser PHP to duża maszyneria na jedną kontrolę heurystyczną.
     */
    private function withoutPhpComments(string $contents): string
    {
        $text = preg_replace('#/\*.*?\*/#s', '', $contents) ?? $contents;
        $lines = [];
        foreach (explode("\n", $text) as $line) {
            // Delimiter `~`, a nie `#`: wzorzec zawiera `#` (komentarz w stylu shella), więc
            // `#` jako delimiter kończyłby wzorzec i PHP zgłaszałby „Unknown modifier”.
            $lines[] = (string) preg_replace('~(//|#\s).*$~', '', $line);
        }

        return implode("\n", $lines);
    }

    private function finding(string $file, int $line, string $text): CheckFinding
    {
        $shown = mb_strlen($text) > 60 ? mb_substr($text, 0, 57) . '…' : $text;

        return new CheckFinding(
            code: self::CODE,
            severity: CheckSeverity::Warning,
            file: $file,
            line: $line,
            text: $shown,
            message: sprintf('Tekst wpisany w kodzie: „%s”. Wyniesienie go do katalogu da tłumaczom szansę.', $shown),
        );
    }
}
