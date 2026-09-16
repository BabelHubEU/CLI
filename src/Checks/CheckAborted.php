<?php

declare(strict_types=1);

namespace BabelHub\Cli\Checks;

use BabelHub\Cli\ExitCode;
use BabelHub\Cli\Http\ApiResponse;
use RuntimeException;

/**
 * Kontroli **nie dało się przeprowadzić** — i to jest inny stan niż „kontrola coś znalazła”.
 *
 * Rozróżnienie jest całym sensem kodów wyjścia (1.16): `6` znaczy „sprawdziłem i jest problem
 * w tłumaczeniach”, a `3`/`4`/`5` znaczą „nie sprawdziłem”. Zlepienie ich w jedno „nie przeszło”
 * kończy się tym, że czerwony build nie mówi, czy ktoś ma poprawić tekst, czy poświadczenie —
 * a to jest dokładnie ten rodzaj cichej porażki, przez który ludzie przestają ufać bramce.
 *
 * Wyjątek niesie gotowe zdanie i kod, bo tylko miejsce, które wie **co** się stało, może je
 * wybrać: kod pochodzi z odpowiedzi serwisu (`ApiResponse::exitCode()`), a nie z tabelki
 * przepisanej w tym pliku.
 */
final class CheckAborted extends RuntimeException
{
    private function __construct(
        string $message,
        private readonly ExitCode $exitCode,
    ) {
        parent::__construct($message);
    }

    /** Serwis odpowiedział błędem — zdanie serwisu jest dla człowieka, ale nazywamy też kontrolę. */
    public static function fromResponse(CheckName $check, ApiResponse $response): self
    {
        return new self(
            sprintf('Kontrola %s: %s', $check->value, $response->message()),
            $response->exitCode(),
        );
    }

    /**
     * Język spoza projektu podany w `--locale`.
     *
     * `coverage` i `quality` dostają w tej sytuacji `422` z serwisu (język nie należy do projektu),
     * ale `acceptance` czyta `GET …/status`, a tam `?locale=` **zawęża tylko ustalenia QA**
     * i nieznany język nie jest błędem. Bez tego guardu literówka (`--locale=pl_PL ` albo
     * `--locale=pl`) dawała zero niezatwierdzonych tłumaczeń, czyli **zieloną bramkę** —
     * a to jest dokładnie ten cichy fałszywy sukces, którego cała ta komenda nie może popełniać.
     *
     * @param list<string> $available języki projektu, żeby komunikat mówił, co wolno wpisać
     */
    public static function unknownLocale(CheckName $check, string $locale, array $available): self
    {
        return new self(
            sprintf(
                'Kontrola %s: projekt nie prowadzi języka „%s” (języki projektu: %s).',
                $check->value,
                $locale,
                $available === [] ? 'brak' : implode(', ', $available),
            ),
            ExitCode::Rejected,
        );
    }

    public function exitCode(): ExitCode
    {
        return $this->exitCode;
    }
}
