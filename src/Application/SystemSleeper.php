<?php

declare(strict_types=1);

namespace BabelHub\Cli\Application;

/**
 * Zwykłe `sleep` — jedyna implementacja `Sleeper` w produkcji.
 *
 * Osobna klasa, a nie `usleep()` w serwisie, bo pętla czekania na parowanie musi być
 * testowalna bez czekania: test, który realnie śpi pięć sekund na iterację, albo trwa
 * minutami, albo (gorzej) nie sprawdza wyjścia z pętli wcale.
 */
final class SystemSleeper implements Sleeper
{
    public function sleep(float $seconds): void
    {
        usleep((int) round($seconds * 1_000_000));
    }
}
