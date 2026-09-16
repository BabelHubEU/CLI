<?php

declare(strict_types=1);

namespace BabelHub\Cli\Output;

use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Rozdzielenie strumieni podane **wprost**, zamiast dziedziczenia po `ConsoleOutput`.
 *
 * Symfony Console ma jedno wyjście, a `ConsoleOutput` dokłada drugie (`getErrorOutput()`),
 * tworząc je samo z `php://stderr`. Do testów i do wbudowania w inne narzędzie potrzebujemy
 * wskazać oba — i właśnie dlatego to klasa, a nie anonimowa atrapa w aplikacji: rozdział
 * wyniku od komunikatów jest **kontraktem CLI** (1.17), więc jego implementacja ma być
 * widoczna i testowalna, a nie ukryta w domknięciu.
 */
final class SplitOutput extends ConsoleOutput
{
    public function __construct(
        private readonly OutputInterface $stdout,
        private readonly OutputInterface $stderr,
    ) {
        parent::__construct();
    }

    public function getErrorOutput(): OutputInterface
    {
        return $this->stderr;
    }

    protected function doWrite(string $message, bool $newline): void
    {
        $this->stdout->write($message, $newline);
    }
}
