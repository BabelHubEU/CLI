<?php

declare(strict_types=1);

namespace BabelHub\Cli\Output;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Jedno miejsce, w którym CLI pisze — z rozdziałem na **wynik** (`stdout`) i **resztę**
 * (`stderr`), wymuszonym przez sam typ, a nie przez dobrą wolę autora komendy.
 *
 * Komenda nie ma tu dostępu do strumienia „domyślnego”: wynik pisze się `result()`,
 * `plain()` albo `json()`, a komunikaty `info()`/`warn()`/`error()`. Dzięki temu tryb `json`
 * może zagwarantować, że na `stdout` poleci **dokładnie jeden** obiekt JSON — a to jest
 * jedyna rzecz, której skrypt parsujący potrzebuje od narzędzia.
 *
 * **Każda linia kończy się jawnym `"\n"`, a nie `PHP_EOL`** — i to jest decyzja, nie szczegół.
 * `OutputInterface::writeln()` dokłada `PHP_EOL`, czyli na Windows `"\r\n"`; dla pliku tekstowego
 * to poprawna konwencja systemowa, ale tutaj łamie kontrakt: `--output=plain` obiecuje „jedna
 * linia na pozycję” dla `grep`/`awk`, `--output=json` — dokładnie jeden dokument, a testy
 * porównują wynik **znak w znak**. Ten sam commit uruchomiony na Windows i na Linuksie dawał
 * więc różne bajty na `stdout` — czyli „działa u mnie” czekające na pierwszą osobę z Windows.
 * Cała weryfikacja CLI przeszła w kontenerze (Linux) i **dziewięć testów wyszło czerwono
 * dopiero przy uruchomieniu bramki na hoście z Windows**; to jest ten rodzaj różnicy, którego
 * nie wolno zostawiać w narzędziu sprzedawanym jako kanał akwizycji.
 */
final readonly class Printer
{
    public function __construct(
        private OutputInterface $stdout,
        private OutputInterface $stderr,
        private OutputMode $mode,
    ) {
    }

    public function mode(): OutputMode
    {
        return $this->mode;
    }

    /** Wynik dla człowieka (tryb `text`); w `plain` i `json` nie wypisuje nic. */
    public function result(string $message): void
    {
        if ($this->mode === OutputMode::Text) {
            $this->line($this->stdout, $message);
        }
    }

    /** Linia wyniku do `grep`/`awk` — wypisywana w trybach `text` i `plain`. */
    public function plain(string $line): void
    {
        if ($this->mode !== OutputMode::Json) {
            $this->line($this->stdout, $line);
        }
    }

    /**
     * Wynik maszynowy. W trybie `json` to jedyna rzecz na `stdout`; w pozostałych trybach
     * **nie wypisujemy go wcale** — mieszanie JSON-a do tekstu dla człowieka to najgorsze
     * z obu światów.
     *
     * @param array<string, mixed> $payload
     */
    public function json(array $payload): void
    {
        if ($this->mode === OutputMode::Json) {
            $this->line($this->stdout, (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }
    }

    /** Postęp i podpowiedzi — zawsze `stderr`, żeby nie zanieczyszczać wyniku. */
    public function info(string $message): void
    {
        $this->line($this->stderr, $message);
    }

    /**
     * Wynik maszynowy **przepisany z serwisu dosłownie**, a nie zakodowany ponownie.
     *
     * Różnica jest widoczna dokładnie w jednym miejscu i właśnie dlatego tu jest: pusta mapa
     * (`{}`) po drodze przez `json_decode` + `json_encode` staje się pustą listą (`[]`). Kontrakt
     * API mówi wprost, że `skipped` jest mapą, więc klient parsujący wynik dostałby inny typ,
     * niż obiecuje serwis — a to jest błąd, który wyskakuje dopiero u kogoś, kto pisze skrypt.
     * `text` i `plain` nie dostają tu nic: one są dla człowieka i mają własne wywołania.
     */
    public function jsonBody(string $body): void
    {
        if ($this->mode !== OutputMode::Json) {
            return;
        }

        $trimmed = trim($body);
        if ($trimmed !== '') {
            $this->line($this->stdout, $trimmed);
        }
    }

    public function warn(string $message): void
    {
        $this->line($this->stderr, '<comment>' . $message . '</comment>');
    }

    public function error(string $message): void
    {
        $this->line($this->stderr, '<error>' . $message . '</error>');
    }

    /**
     * Linia z **jawnym terminatorem**. `write()` (a nie `writeln()`) zachowuje formatowanie tagów
     * Symfony, więc różnica wobec poprzedniej wersji to wyłącznie `PHP_EOL` → `"\n"`.
     */
    private function line(OutputInterface $output, string $message): void
    {
        $output->write($message . "\n");
    }
}
