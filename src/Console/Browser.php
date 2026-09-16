<?php

declare(strict_types=1);

namespace BabelHub\Cli\Console;

/**
 * Otwarcie przeglądarki — **wygoda, nigdy warunek działania**.
 *
 * `babelhub login` musi działać w środowiskach, w których nie ma czym otworzyć przeglądarki:
 * kontener CI, SSH, serwer bez `xdg-open`. Dlatego niepowodzenie tej operacji nie jest błędem
 * i nie zmienia kodu wyjścia — adres i kod i tak zostały wypisane, więc człowiek może je
 * przenieść ręcznie. Odwrotna kolejność (najpierw przeglądarka, potem komunikat) kończy się
 * tym, że w kontenerze nie widać ani jednego, ani drugiego.
 */
final readonly class Browser implements BrowserInterface
{
    public function open(string $url): bool
    {
        if (!function_exists('exec')) {
            return false;
        }

        $command = match (PHP_OS_FAMILY) {
            'Windows' => 'start "" ' . escapeshellarg($url),
            'Darwin' => 'open ' . escapeshellarg($url),
            default => 'xdg-open ' . escapeshellarg($url),
        };

        try {
            // Wyjście i kod powrotu są tu bez znaczenia (a na Windows `start` bywa dziwne),
            // więc nie sprawdzamy ich — liczy się tylko brak wyjątku.
            exec($command . ' 2>/dev/null');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
