<?php

declare(strict_types=1);

namespace Tests\Cli\Support;

use BabelHub\Cli\Http\ApiClientInterface;
use BabelHub\Cli\Http\ApiException;
use BabelHub\Cli\Http\ApiResponse;
use RuntimeException;

/**
 * Klient HTTP w pamięci — bo zachowanie CLI musi być sprawdzalne **bez serwisu**.
 *
 * Odpowiedzi są skryptowane po `METHOD ścieżka`, a nie „po kolei”: dzięki temu test czyta się
 * jak opis przepływu (`odpowiedzNa('POST', '/api/v1/cli/token', ['status' => 'pending'])`),
 * a nie jak lista, w której trzeba liczyć wywołania. Żądania są zapisywane, bo część reguł
 * dotyczy właśnie ich: czy `--token` został zweryfikowany **przed** zapisaniem, czy pętla
 * odpytywania nie wysyła niczego po zakończeniu.
 */
final class FakeApiClient implements ApiClientInterface
{
    /** @var list<array{method: string, path: string, payload: array<string, mixed>, headers: array<string, string>}> */
    public array $requests = [];

    /** @var array<string, list<ApiResponse>> */
    private array $scripted = [];

    /** Powód awarii transportu; `null` = atrapa odpowiada z kolejek. */
    private ?string $transportFailure = null;

    public function __construct(
        private string $baseUrl = 'https://babelhub.example',
        private ?string $token = null,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, list<string>> $headers
     */
    public function script(string $method, string $path, array $payload, int $status = 200, array $headers = []): self
    {
        $this->scripted[$this->key($method, $path)][] = new ApiResponse(
            status: $status,
            body: (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            headers: $headers,
        );

        return $this;
    }

    /** @param array<string, list<string>> $headers */
    public function scriptRaw(string $method, string $path, int $status, string $body, array $headers = []): self
    {
        $this->scripted[$this->key($method, $path)][] = new ApiResponse($status, $body, $headers);

        return $this;
    }

    /**
     * Awaria **transportu**, nie odpowiedź: „nie połączyłem się” zamiast „serwis powiedział nie”.
     *
     * To osobny przypadek w kontrakcie kodów wyjścia (1.16), bo tylko on daje `ApiException`
     * z kodem `1` — a komunikat musi powiedzieć, że problem jest w adresie albo sieci, zamiast
     * pokazywać kod HTTP, którego w ogóle nie było. Atrapa rzuca tym samym wyjątkiem, co prawdziwy
     * klient, więc komenda nie ma jak odróżnić testu od produkcji (i dobrze).
     */
    public function failTransport(string $reason = 'Connection refused'): self
    {
        $this->transportFailure = $reason;

        return $this;
    }

    /** @param array<string, string|int> $query */
    public function get(string $path, array $query = [], array $headers = []): ApiResponse
    {
        $this->requests[] = ['method' => 'GET', 'path' => $path, 'payload' => $query, 'headers' => $headers];

        return $this->next('GET', $path);
    }

    /** @param array<string, mixed> $payload */
    public function post(string $path, array $payload = []): ApiResponse
    {
        $this->requests[] = ['method' => 'POST', 'path' => $path, 'payload' => $payload, 'headers' => []];

        return $this->next('POST', $path);
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function withToken(?string $token): ApiClientInterface
    {
        // Mutujemy atrapę (a nie klonujemy), bo test ma widzieć **to samo** poświadczenie,
        // którym poszło żądanie — klon gubiłby nagłówek z oczu asercji.
        $this->token = $token;

        return $this;
    }

    /** Token, z którym poszło ostatnie żądanie — do sprawdzenia, że użyto właściwego. */
    public function token(): ?string
    {
        return $this->token;
    }

    public function callCount(string $method, string $path): int
    {
        $count = 0;
        foreach ($this->requests as $request) {
            if ($request['method'] === $method && $request['path'] === $path) {
                $count++;
            }
        }

        return $count;
    }

    private function next(string $method, string $path): ApiResponse
    {
        if ($this->transportFailure !== null) {
            throw ApiException::transport($this->baseUrl . $path, $this->transportFailure);
        }

        $key = $this->key($method, $path);
        $queue = $this->scripted[$key] ?? [];
        if ($queue === []) {
            throw new RuntimeException(sprintf('Nie przygotowano odpowiedzi na %s %s.', $method, $path));
        }

        // Ostatnia przygotowana odpowiedź powtarza się — pętla odpytywania ma dostać ten sam
        // stan, dopóki test nie powie inaczej.
        if (count($queue) === 1) {
            return $queue[0];
        }

        $next = array_shift($this->scripted[$key]);
        if (!$next instanceof ApiResponse) {
            throw new RuntimeException(sprintf('Kolejka odpowiedzi na %s %s jest uszkodzona.', $method, $path));
        }

        return $next;
    }

    private function key(string $method, string $path): string
    {
        return $method . ' ' . $path;
    }
}
