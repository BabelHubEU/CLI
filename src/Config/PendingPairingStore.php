<?php

declare(strict_types=1);

namespace BabelHub\Cli\Config;

use DateTimeImmutable;
use BabelHub\Cli\CliIoException;

/**
 * Parowanie rozpoczęte i jeszcze nie dokończone — **na dysku użytkownika, nie w argumentach**.
 *
 * Skąd ten plik: `device_code` jest sekretem terminala (to nim CLI odbiera poświadczenie),
 * a `user_code` jest tym, co człowiek widzi i przepisuje. Bez TTY pierwsze polecenie kończy
 * się, zanim ktokolwiek zatwierdzi cokolwiek w przeglądarce — więc drugie polecenie
 * (`login --complete=<url>`) musi skądś wziąć `device_code`. Trzy możliwe rozwiązania
 * i powód, dla którego wygrywa to:
 *
 * 1. *wypisać `device_code` w JSON-ie i kazać go wkleić* — czyli przemycić sekret do historii
 *    powłoki i do logów CI; dokładnie to, czego ten przepływ ma unikać;
 * 2. *pozwolić odebrać token po samym `user_code`* — a `user_code` jedzie w adresie, więc
 *    trafia do historii przeglądarki, nagłówka `Referer` i zrzutów ekranu; kto go zobaczy,
 *    ten odbiera cudze poświadczenie;
 * 3. **trzymać `device_code` lokalnie** (ten plik, prawa 0600, kasowany w chwili zakończenia
 *    parowania), a `--complete=<url>` używać wyłącznie jako **potwierdzenia, który kod**
 *    człowiek zatwierdził.
 *
 * Wariant 3 nie osłabia niczego: plik leży tam, gdzie i tak leży gotowe poświadczenie,
 * a adres z przeglądarki nie wystarcza do odebrania tokenu.
 */
final class PendingPairingStore
{
    private const FILE_NAME = 'pending-pairing.json';

    public function __construct(private readonly string $directory)
    {
    }

    public static function alongsideCredentials(string $credentialsPath): self
    {
        return new self(dirname($credentialsPath));
    }

    public function save(PendingPairing $pairing): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new CliIoException(sprintf('Nie mogę utworzyć katalogu %s.', $this->directory));
        }

        $encoded = json_encode($pairing->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false || file_put_contents($this->path(), $encoded . "\n") === false) {
            throw new CliIoException(sprintf('Nie udało się zapisać stanu parowania w %s.', $this->path()));
        }

        @chmod($this->path(), 0600);
    }

    public function load(): ?PendingPairing
    {
        if (!is_file($this->path())) {
            return null;
        }

        $raw = file_get_contents($this->path());
        if ($raw === false || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? PendingPairing::fromArray($decoded) : null;
    }

    public function forget(): void
    {
        if (is_file($this->path())) {
            @unlink($this->path());
        }
    }

    public function path(): string
    {
        return rtrim($this->directory, '/\\') . '/' . self::FILE_NAME;
    }

    /** Parowanie, które już wygasło, nie jest niczego warte — traktujemy je jak brak. */
    public function loadValid(?DateTimeImmutable $now = null): ?PendingPairing
    {
        $pairing = $this->load();
        if ($pairing === null) {
            return null;
        }

        if ($pairing->isExpired($now ?? new DateTimeImmutable())) {
            $this->forget();

            return null;
        }

        return $pairing;
    }
}
