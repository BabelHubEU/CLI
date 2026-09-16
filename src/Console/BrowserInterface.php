<?php

declare(strict_types=1);

namespace BabelHub\Cli\Console;

/**
 * Otwieranie przeglądarki jako port — wyłącznie po to, żeby dało się to **udawać w testach**.
 *
 * W produkcji jest jedna implementacja (`Browser`) i jej niepowodzenie nie jest błędem:
 * adres i kod są wypisane, więc człowiek w kontenerze czy po SSH poradzi sobie sam. Port
 * istnieje, bo test przepływu parowania nie może zależeć od tego, czy na maszynie CI jest
 * `xdg-open` — a wywołanie `exec` w teście jest dokładnie tym, czego w bramce nie chcemy.
 */
interface BrowserInterface
{
    public function open(string $url): bool;
}
