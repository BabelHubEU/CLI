<?php

declare(strict_types=1);

namespace Tests\Cli\Support;

use BabelHub\Cli\Console\BrowserInterface;

/**
 * Przeglądarka, która tylko notuje — test nie może zależeć od tego, czy na maszynie CI jest
 * `xdg-open`, a `Browser` i tak nie jest warunkiem działania (patrz jego komentarz):
 * niepowodzenie otwarcia przeglądarki nie zmienia kodu wyjścia.
 */
final class RecordingBrowser implements BrowserInterface
{
    /** @var list<string> */
    public array $opened = [];

    public function __construct(private readonly bool $succeeds = true)
    {
    }

    public function open(string $url): bool
    {
        $this->opened[] = $url;

        return $this->succeeds;
    }
}
