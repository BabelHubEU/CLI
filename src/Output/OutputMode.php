<?php

declare(strict_types=1);

namespace BabelHub\Cli\Output;

/**
 * Trzy tryby wyjścia (1.17) z **twardym podziałem strumieni**.
 *
 * `plain` ma się składać z `grep`/`awk`, `json` ma być do parsowania — a oba te zastosowania
 * psuje jedna rzecz: domieszanie postępu i ostrzeżeń do `stdout`. Dlatego wynik idzie na
 * `stdout`, a wszystko, co nie jest wynikiem (postęp, ostrzeżenia, błędy), na `stderr`.
 * W trybie `text` wygląda to jak normalne CLI; w `plain` i `json` na `stdout` nie pada
 * **ani jedno zdanie**, którego nie da się sparsować.
 */
enum OutputMode: string
{
    case Text = 'text';
    case Plain = 'plain';
    case Json = 'json';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $mode): string => $mode->value, self::cases());
    }

    /** Nieznany tryb to błąd użycia — „cichy fallback na text” psułby parsujące skrypty. */
    public static function tryParse(string $value): ?self
    {
        return self::tryFrom(mb_strtolower(trim($value)));
    }
}
