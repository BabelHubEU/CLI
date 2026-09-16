<?php

declare(strict_types=1);

namespace BabelHub\Cli\Scan;

use RuntimeException;

/**
 * Adapter PHP/Twig: znajduje klucze tłumaczeń w kodzie (pozycja 1.3 planu).
 *
 * Zakres jest **wąski i świadomy**. Szukamy wyłącznie **literałów** w wywołaniach, które
 * w tym ekosystemie naprawdę występują:
 *
 * | Wzorzec | Gdzie |
 * | --- | --- |
 * | `'klucz'\|trans`, `"klucz"\|trans` | Twig |
 * | `{% trans %}klucz{% endtrans %}` | Twig (styl Symfony) |
 * | `trans('klucz')`, `->trans('klucz')` | PHP |
 *
 * Czego nie robimy i dlaczego: nie parsujemy AST ani nie zgadujemy kluczy składanych
 * (`t('a.' ~ $x)`). Klucz dynamiczny jest z definicji nieznany w chwili skanu, a **fałszywy
 * klucz jest gorszy niż brak klucza** — trafiłby do serwisu jako nowy, pusty wpis i ktoś
 * musiałby go ręcznie usunąć. Dlatego wolimy powiedzieć „skan znalazł 240 kluczy” i pokazać
 * pliki, niż udawać, że wiemy więcej, niż wiemy.
 *
 * Katalogi `vendor`, `node_modules`, `var`, `.git` i katalogi cache są pomijane: tłumaczenia
 * bibliotek nie są tłumaczeniami projektu, a skanowanie ich dawałoby tysiące kluczy, których
 * nikt nie może zmienić.
 */
final readonly class PhpTwigScanner
{
    public function __construct(private SourceFiles $files = new SourceFiles())
    {
    }

    private const PHP_PATTERNS = [
        // `trans('klucz')`, `->trans('klucz')`, `$this->translator->trans('klucz')`
        '/(?:->|::|(?<![\w>$]))trans\(\s*\'([a-z][a-zA-Z0-9_]*(?:\.[a-z][a-zA-Z0-9_]*)+)\'/',
        '/(?:->|::|(?<![\w>$]))trans\(\s*"([a-z][a-zA-Z0-9_]*(?:\.[a-z][a-zA-Z0-9_]*)+)"/',
    ];

    private const TWIG_PATTERNS = [
        // `{{ 'klucz'|trans }}` — z odstępami i bez, także z filtrem i argumentami
        '/\'([a-z][a-zA-Z0-9_]*(?:\.[a-z][a-zA-Z0-9_]*)+)\'\s*\|\s*trans/',
        '/"([a-z][a-zA-Z0-9_]*(?:\.[a-z][a-zA-Z0-9_]*)+)"\s*\|\s*trans/',
        // `{% trans %}klucz{% endtrans %}` — klucz bywa tam gołym napisem bez cudzysłowów
        '/\{%\s*trans\s*%\}([a-z][a-zA-Z0-9_]*(?:\.[a-z][a-zA-Z0-9_]*)+)\{%\s*endtrans\s*%\}/',
    ];

    /**
     * @param string $root katalog projektu (korzeń repozytorium)
     * @param list<string> $paths katalogi do przeskanowania, względem korzenia
     *
     * @throws RuntimeException gdy któryś katalog nie istnieje — literówka w konfiguracji
     *                          nie może cicho dać „0 kluczy” i skończyć się push-em pustej partii
     */
    public function scan(string $root, array $paths): ScanResult
    {
        $occurrences = [];
        $files = $this->files->in($root, $paths);

        foreach ($files as $file) {
            $this->collect($root . '/' . $file, $file, $occurrences);
        }

        ksort($occurrences);

        return new ScanResult($occurrences, $files);
    }

    /**
     * @param array<string, list<string>> $occurrences
     */
    private function collect(string $absolutePath, string $relativePath, array &$occurrences): void
    {
        $contents = file_get_contents($absolutePath);
        if ($contents === false || $contents === '') {
            return;
        }

        $patterns = str_ends_with($relativePath, '.twig')
            ? self::TWIG_PATTERNS
            : self::PHP_PATTERNS;

        foreach ($patterns as $pattern) {
            $matches = [];
            if (preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE) === false) {
                continue;
            }

            // `PREG_OFFSET_CAPTURE` daje parę (napis, offset), więc bez rzutowania PHPStan widzi
            // typy native; rzutujemy wprost, zamiast opisywać je adnotacją, która i tak musi
            // powtórzyć typ natywny (inaczej analiza słusznie zgłasza rozjazd).
            foreach ($matches[1] as $match) {
                $key = (string) $match[0];
                $offset = (int) $match[1];
                $line = substr_count(substr($contents, 0, $offset), "\n") + 1;
                $location = sprintf('%s:%d', $relativePath, $line);

                if (!in_array($location, $occurrences[$key] ?? [], true)) {
                    $occurrences[$key][] = $location;
                }
            }
        }
    }
}
