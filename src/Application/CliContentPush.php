<?php

declare(strict_types=1);

namespace BabelHub\Cli\Application;

use BabelHub\Cli\Content\ContentDirectory;
use BabelHub\Cli\ExitCode;
use BabelHub\Cli\Http\ApiClientInterface;
use BabelHub\Cli\Output\Printer;

/**
 * `babelhub content:push` — treści z repozytorium do serwisu.
 *
 * Jedno żądanie **na treść**, a nie jedno zbiorcze: serwis przyjmuje upsert pojedynczego wpisu,
 * a raport z częściowym sukcesem jest uczciwszy niż „cała partia padła, bo jedna treść ma
 * nieznaną zmienną”. Dzięki temu trzy z pięciu treści wejdą, a dwie wrócą z konkretnym powodem.
 *
 * `--dry-run` czyta pliki, ale **nie wysyła niczego** — ta sama zasada co w `push`: podgląd
 * ma odpowiedzieć „czy widzę to, co myślę”, zanim cokolwiek zmieni się w projekcie.
 */
final readonly class CliContentPush
{
    public function __construct(
        private ApiClientInterface $client,
        private Printer $printer,
    ) {
    }

    public function run(string $project, ContentDirectory $directory, bool $dryRun): int
    {
        $items = $directory->read();
        if ($items === []) {
            $this->printer->error(sprintf('Brak treści w %s. Utwórz katalog <klucz>/ z meta.json i source.html.', $directory->path));

            return ExitCode::Usage->value;
        }

        $failed = 0;
        foreach ($items as $item) {
            if ($dryRun) {
                $this->printer->result(sprintf(
                    '[dry-run] %s (%s) — %d języków, %d zmiennych',
                    $item->key,
                    $item->type,
                    count($item->variants),
                    count($item->variables),
                ));
                continue;
            }

            $variants = [];
            foreach ($item->variants as $locale => $body) {
                $variants[$locale] = ['body' => $body];
            }

            $response = $this->client->post('/api/v1/projects/' . rawurlencode($project) . '/content', [
                'key' => $item->key,
                'type' => $item->type,
                'source' => $item->source,
                'variables' => $item->variables,
                'variants' => $variants,
            ]);

            if ($response->isSuccess()) {
                $this->printer->result(sprintf('%s (%s) — zapisane', $item->key, $item->type));
                continue;
            }

            $failed++;
            $this->printer->error(sprintf('%s: %s', $item->key, $response->message()));
        }

        if ($failed > 0) {
            $this->printer->error(sprintf('%d z %d treści nie weszło.', $failed, count($items)));

            return ExitCode::Failure->value;
        }

        $this->printer->result($dryRun
            ? sprintf('Dry-run: %d treści gotowych do wysłania.', count($items))
            : sprintf('Wysłano %d treści.', count($items)));

        return ExitCode::Success->value;
    }
}
