<?php

declare(strict_types=1);

namespace Tests\Cli\Support;

use BabelHub\Cli\Application\Sleeper;

/**
 * Czekanie bez czekania.
 *
 * Licznik wywołań jest tu istotny, bo test pętli parowania musi dowieść dwóch rzeczy naraz:
 * że CLI **czeka** między odpytaniami (nie zasypuje serwera) i że **wychodzi** z pętli.
 * Bez licznika jedno z tych dwóch jest nieodróżnialne od drugiego.
 */
final class FakeSleeper implements Sleeper
{
    public int $calls = 0;
    public float $slept = 0.0;

    public function sleep(float $seconds): void
    {
        $this->calls++;
        $this->slept += $seconds;
    }
}
