<?php

declare(strict_types=1);

namespace BabelHub\Cli\Http;

use BabelHub\Cli\ExitCode;
use RuntimeException;

/**
 * Błąd, po którym **nie ma czego pokazywać** — nie dotarliśmy do serwisu.
 *
 * Odróżnienie „serwis odpowiedział błędem” (mamy ciało, kod błędu i zdanie — patrz
 * `ApiResponse`) od „serwis nie odpowiedział” jest istotne dla człowieka czytającego log CI:
 * pierwsze znaczy „popraw dane”, drugie „sprawdź adres, sieć albo czy usługa żyje”. Zlepienie
 * ich w jedno „błąd” kosztuje najwięcej czasu dokładnie wtedy, gdy jest się w pośpiechu.
 *
 * `exitCode` jest częścią wyjątku, bo tylko miejsce, które wie, **co** się stało, może wybrać
 * kod wyjścia — a reguła „brak zera przy niepowodzeniu” ma obowiązywać także tutaj.
 */
final class ApiException extends RuntimeException
{
    private function __construct(
        string $message,
        private readonly ExitCode $exitCode,
    ) {
        parent::__construct($message);
    }

    public static function transport(string $url, string $reason): self
    {
        return new self(sprintf('Nie udało się połączyć z %s: %s', $url, $reason), ExitCode::Failure);
    }

    /** Upłynął czas oczekiwania — najczęstszy powód „CLI wisi” w CI (u konkurencji 700 s!). */
    public static function timeout(string $url, float $seconds): self
    {
        return new self(
            sprintf('Przekroczono limit %.1f s oczekiwania na %s. Podnieś go flagą --timeout, jeśli serwis odpowiada wolno.', $seconds, $url),
            ExitCode::Failure,
        );
    }

    public static function invalidJson(string $url): self
    {
        return new self(
            sprintf(
                '%s odpowiedział czymś, co nie jest JSON-em. Sprawdź, czy adres wskazuje na BabelHub ' .
                '(a nie na proxy albo stronę logowania).',
                $url,
            ),
            ExitCode::Failure,
        );
    }

    public function exitCode(): ExitCode
    {
        return $this->exitCode;
    }
}
