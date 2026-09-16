<?php

declare(strict_types=1);

namespace BabelHub\Cli\Git;

/**
 * Stan kodu w repozytorium: commit, gałąź i to, czy pliki czytane przez `push` są z nim zgodne.
 *
 * `pathsUnchanged` jest osobnym pytaniem, a nie „czy drzewo jest czyste”, i to jest cała
 * subtelność tego znacznika: `sync` po `pull` **zostawia zmienione pliki** (katalogi docelowe),
 * więc „brudne drzewo” jest normalnym stanem po poprawnym przebiegu. Pytać trzeba tylko o to,
 * co `push` naprawdę wysyła — drzewo kodu i plik źródłowy. Inaczej kolejny `sync` wysyłałby
 * wszystko jeszcze raz dokładnie w tym momencie, w którym mechanizm ma oszczędzać.
 */
final readonly class CodeRevision
{
    public function __construct(
        public string $commit,
        public string $branch,
        public bool $pathsUnchanged,
    ) {
    }

    /** Krótki opis do komunikatu: `1a2b3c4 (main)`. */
    public function label(): string
    {
        return sprintf('%s (%s)', mb_substr($this->commit, 0, 7), $this->branch);
    }
}
