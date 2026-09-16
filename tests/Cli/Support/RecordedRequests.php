<?php

declare(strict_types=1);

namespace Tests\Cli\Support;

use RuntimeException;

/**
 * Żądania, które faktycznie poszły — czytane z `FakeApiClient::$requests`.
 *
 * `FakeApiClient` zapisuje **każde** żądanie razem z nagłówkami i ciałem, bo część kontraktu CLI
 * dotyczy właśnie ich: `If-None-Match` przy `pull` (to on sprawia, że pliki nie są przepisywane),
 * kolejność `push` → `pull` przy `sync` (`pull` na niekompletnym stanie daje pliki, których nikt
 * nie zweryfikował) i brak pola `locale` w ciele QA, gdy nie podano `--locale`.
 *
 * Te dostępy są osobną klasą, a nie `array_column`-em w każdym teście, z jednego powodu:
 * brak żądania musi być **czytelnym błędem** („nie było żądania POST …/qa”), a nie `null`-em,
 * który w asercji wygląda jak „pole się nie zgadza”. Milczący `null` w tym miejscu zamieniłby
 * test „CLI nie wysłało żądania” w test, który tego w ogóle nie sprawdza.
 */
final class RecordedRequests
{
    public function __construct(private readonly FakeApiClient $client)
    {
    }

    /** @return list<string> ścieżki w kolejności wywołań — kolejność jest tu częścią kontraktu */
    public function paths(): array
    {
        return array_values(array_column($this->client->requests, 'path'));
    }

    /** @return list<string> */
    public function methods(): array
    {
        return array_values(array_column($this->client->requests, 'method'));
    }

    public function count(): int
    {
        return count($this->client->requests);
    }

    /**
     * Ile razy pytano o **dokładnie tę** ścieżkę.
     *
     * Potrzebne tam, gdzie kontraktem jest *brak* żądania: `pull` z `download.locales: [pl_PL]`
     * nie może nawet zapytać o `en_GB`, bo wtedy limit z konfiguracji ograniczałby tylko zapis,
     * a nie ruch — a to jest różnica widoczna w dzienniku użycia i w limiterze.
     */
    public function countPath(string $path): int
    {
        $count = 0;
        foreach ($this->client->requests as $request) {
            if ($request['path'] === $path) {
                $count++;
            }
        }

        return $count;
    }

    /** @return array<string, mixed> ciało żądania (dla `GET` — parametry zapytania) */
    public function payload(string $method, string $path, int $occurrence = 0): array
    {
        return $this->at($method, $path, $occurrence)['payload'];
    }

    /** @return array<string, string> */
    public function headers(string $method, string $path, int $occurrence = 0): array
    {
        return $this->at($method, $path, $occurrence)['headers'];
    }

    /**
     * @return array{method: string, path: string, payload: array<string, mixed>, headers: array<string, string>}
     */
    private function at(string $method, string $path, int $occurrence): array
    {
        $seen = 0;
        foreach ($this->client->requests as $request) {
            if ($request['method'] !== $method || $request['path'] !== $path) {
                continue;
            }

            if ($seen === $occurrence) {
                return $request;
            }

            $seen++;
        }

        throw new RuntimeException(sprintf(
            'Nie było żądania %s %s (wystąpienie %d). Zapisane żądania: %s.',
            $method,
            $path,
            $occurrence,
            $this->client->requests === [] ? 'brak' : implode(', ', $this->paths()),
        ));
    }
}
