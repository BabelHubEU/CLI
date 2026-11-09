<?php

declare(strict_types=1);

namespace BabelHub\Cli\Application;

use BabelHub\Cli\Content\ContentDirectory;
use BabelHub\Cli\Content\ContentItemFile;
use BabelHub\Cli\ExitCode;
use BabelHub\Cli\Http\ApiClientInterface;
use BabelHub\Cli\Output\Printer;

/**
 * `babelhub content:pull` — treści z serwisu do plików.
 *
 * Najpierw lista (metadane), potem **po jednym** żądaniu na treść, żeby pobrać ciała wariantów:
 * lista ich nie niesie, bo przy projekcie z setką szablonów byłyby to megabajty tekstu.
 *
 * Zapis nadpisuje tylko pliki formatu (`meta.json`, `source.html`, `<locale>.html`) i nie kasuje
 * niczego, czego CLI nie utworzyło — w katalogu treści może leżeć README albo obrazek.
 */
final readonly class CliContentPull
{
    public function __construct(
        private ApiClientInterface $client,
        private Printer $printer,
    ) {
    }

    public function run(string $project, ContentDirectory $directory, bool $dryRun): int
    {
        $list = $this->client->get('/api/v1/projects/' . rawurlencode($project) . '/content');
        if (!$list->isSuccess()) {
            $this->printer->error($list->message());

            return ExitCode::Failure->value;
        }

        $items = $list->json()['items'] ?? [];
        if (!is_array($items) || $items === []) {
            $this->printer->result('Brak treści w projekcie.');

            return ExitCode::Success->value;
        }

        $written = 0;
        foreach ($items as $summary) {
            $key = is_array($summary) && is_string($summary['key'] ?? null) ? $summary['key'] : '';
            if ($key === '') {
                continue;
            }

            $detail = $this->client->get('/api/v1/projects/' . rawurlencode($project) . '/content/' . rawurlencode($key));
            if (!$detail->isSuccess()) {
                $this->printer->error(sprintf('%s: %s', $key, $detail->message()));
                continue;
            }

            $body = $detail->json();
            $variants = [];
            foreach (is_array($body['variants'] ?? null) ? $body['variants'] : [] as $locale => $variant) {
                if (is_string($locale) && is_array($variant) && is_string($variant['body'] ?? null)) {
                    $variants[$locale] = $variant['body'];
                }
            }

            $item = new ContentItemFile(
                key: $key,
                type: is_string($body['type'] ?? null) ? $body['type'] : '',
                source: is_string($body['source'] ?? null) ? $body['source'] : '',
                variables: array_values(array_filter((array) ($body['variables'] ?? []), 'is_string')),
                variants: $variants,
            );

            if ($dryRun) {
                $this->printer->result(sprintf('[dry-run] %s (%s) — %d języków', $key, $item->type, count($variants)));
                continue;
            }

            $directory->writeVariants($item);
            $written++;
            $this->printer->result(sprintf('%s (%s) — zapisane', $key, $item->type));
        }

        $this->printer->result($dryRun
            ? sprintf('Dry-run: %d treści do zapisania.', count($items))
            : sprintf('Zapisano %d treści w %s.', $written, $directory->path));

        return ExitCode::Success->value;
    }
}
