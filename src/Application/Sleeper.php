<?php

declare(strict_types=1);

namespace BabelHub\Cli\Application;

/**
 * Czekanie wydzielone za port, bo **pętla odpytywania to logika, którą trzeba przetestować**.
 *
 * `babelhub login` czeka na decyzję człowieka: pyta serwis co kilka sekund i kończy, gdy
 * poświadczenie jest gotowe, gdy ktoś odmówił albo gdy minął czas. Test tej pętli nie może
 * spać pięciu sekund na iterację — a test, który nie sprawdza wyjścia z pętli, przepuszcza
 * dokładnie ten błąd, który w CLI boli najbardziej: narzędzie, które nigdy nie wraca.
 */
interface Sleeper
{
    public function sleep(float $seconds): void;
}
