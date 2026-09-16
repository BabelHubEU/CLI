<?php

declare(strict_types=1);

namespace BabelHub\Cli\Scan;

/**
 * Co znalazł skan: klucze i **gdzie** je znalazł.
 *
 * Miejsca występowania nie są ozdobą: bez nich `scan` jest listą kluczy, której nie da się
 * zweryfikować, a „skąd ten klucz?” to pierwsze pytanie przy każdym nieoczekiwanym wpisie.
 * W kolejnych falach ta sama informacja zasila kontrolę `hardcoded-strings` i wykrywanie
 * kluczy nieużywanych (plan §11) — więc zbieramy ją od początku, a nie od momentu, w którym
 * ktoś o nią poprosi.
 */
final readonly class ScanResult
{
    /**
     * @param array<string, list<string>> $occurrences klucz => lista „ścieżka:linia”
     * @param list<string> $files lista przeskanowanych plików (względem katalogu projektu)
     */
    public function __construct(
        public array $occurrences,
        public array $files = [],
    ) {
    }

    /** @return list<string> */
    public function keys(): array
    {
        $keys = array_keys($this->occurrences);
        sort($keys);

        return $keys;
    }

    public function count(): int
    {
        return count($this->occurrences);
    }

    public function filesScanned(): int
    {
        return count($this->files);
    }

    /** @return list<string> */
    public function where(string $key): array
    {
        return $this->occurrences[$key] ?? [];
    }
}
