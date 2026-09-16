<?php

declare(strict_types=1);

namespace BabelHub\Cli\Http;

/**
 * Klient HTTP CLI — **curl, nie `file_get_contents`**.
 *
 * Trzy rzeczy, których ręcznie sklecone żądanie na strumieniach nie robi dobrze, a które
 * decydują o tym, czy CLI nadaje się do CI:
 *
 * 1. **timeouty** (osobno na połączenie i na całość) — narzędzie, które „wisi” 700 sekund
 *    domyślnie, jest bezużyteczne w pipeline (to realna skarga na CLI Lokalise); u nas limit
 *    jest krótki i konfigurowalny;
 * 2. **kody odpowiedzi i nagłówki** — bez `CURLOPT_HEADER` nie ma `Retry-After`, a bez niego
 *    „poczekaj” nie mówi, ile;
 * 3. **rozróżnienie „nie połączyłem się” od „dostałem błąd”** — pierwsze jest awarią
 *    środowiska, drugie odpowiedzią serwisu, i tylko jedna z nich nadaje się do powtórzenia.
 *
 * Klient nie interpretuje odpowiedzi: zwraca `ApiResponse`, a decyzję (kod wyjścia, zdanie)
 * podejmuje komenda razem z `ExitCode`. Jedno miejsce na politykę, jedno na transport.
 */
final readonly class ApiClient implements ApiClientInterface
{
    public const DEFAULT_TIMEOUT_SECONDS = 15.0;
    public const DEFAULT_CONNECT_TIMEOUT_SECONDS = 5.0;

    public function __construct(
        private string $baseUrl,
        private ?string $token = null,
        private float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        private float $connectTimeoutSeconds = self::DEFAULT_CONNECT_TIMEOUT_SECONDS,
        private string $userAgent = 'babelhub-cli',
    ) {
    }

    /** @param array<string, string|int> $query */
    public function get(string $path, array $query = [], array $headers = []): ApiResponse
    {
        $url = $this->url($path, $query);

        return $this->send('GET', $url, null, $headers);
    }

    /** @param array<string, mixed> $payload */
    public function post(string $path, array $payload = []): ApiResponse
    {
        return $this->send('POST', $this->url($path), $payload);
    }

    /**
     * Adres bazowy z poświadczenia albo z otoczenia — bez końcowego ukośnika, żeby składanie
     * ścieżek nie tworzyło `//api/v1/...` (serwery różnie to normalizują, a podpisy w webhookach
     * już nie).
     */
    public function baseUrl(): string
    {
        return rtrim($this->baseUrl, '/');
    }

    /**
     * Limit oczekiwania, z którym powstał ten klient — **do odczytu przez testy kontraktu**.
     *
     * `--timeout` jest obietnicą wobec CI („nie wisimy 700 sekund”), a obietnica bez sprawdzenia
     * rozjeżdża się przy pierwszym refaktorze konstruktora. Getter jest tańszy niż refleksja
     * w teście i nie udaje, że limit to szczegół wewnętrzny: to część kontraktu CLI.
     */
    public function timeoutSeconds(): float
    {
        return $this->timeoutSeconds;
    }

    public function withToken(?string $token): ApiClientInterface
    {
        return new self($this->baseUrl, $token, $this->timeoutSeconds, $this->connectTimeoutSeconds, $this->userAgent);
    }

    /** @param array<string, string|int> $query */
    private function url(string $path, array $query = []): string
    {
        $url = $this->baseUrl() . '/' . ltrim($path, '/');
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        return $url;
    }

    /**
     * @param array<string, mixed>|null $payload
     * @param array<string, string> $extraHeaders
     */
    private function send(string $method, string $url, ?array $payload, array $extraHeaders = []): ApiResponse
    {
        if ($url === '' || $method === '') {
            throw ApiException::transport($this->baseUrl(), 'adres żądania jest pusty — brak adresu serwisu');
        }

        $handle = curl_init();
        if ($handle === false) {
            throw ApiException::transport($url, 'nie udało się zainicjować curl');
        }

        $headers = ['Accept: application/json', 'User-Agent: ' . $this->userAgent];
        foreach ($extraHeaders as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }
        if ($this->token !== null && $this->token !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        $responseHeaders = [];

        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT_MS => (int) round($this->timeoutSeconds * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) round($this->connectTimeoutSeconds * 1000),
            CURLOPT_HTTPHEADER => $headers,
            // Nagłówki zbieramy własnym callbackiem, bo `CURLOPT_HEADERFUNCTION` nie miesza
            // ich z ciałem — a `Retry-After` musimy mieć osobno od treści odpowiedzi.
            CURLOPT_HEADERFUNCTION => static function ($_handle, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[trim($parts[0])][] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);

        if ($payload !== null) {
            // Ciało dokładamy osobną opcją, a nie w tablicy powyżej: `curl_setopt_array` wymaga
            // od `CURLOPT_POSTFIELDS` **niepustego** napisu, a `json_encode` ma w sygnaturze
            // `string|false`. `JSON_THROW_ON_ERROR` oznacza, że `false` nie wystąpi (lecącym
            // wyjątkiem), więc puste ciało POST-a nie ma jak powstać — a `curl_setopt`
            // przyjmuje wartość bez zawężania typu.
            $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $headers[] = 'Content-Type: application/json';

            curl_setopt($handle, CURLOPT_POSTFIELDS, $encoded);
            curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
        }

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $errorNumber = curl_errno($handle);
        $error = curl_error($handle);

        if ($errorNumber === CURLE_OPERATION_TIMEDOUT) {
            throw ApiException::timeout($url, $this->timeoutSeconds);
        }

        if ($body === false || $errorNumber !== 0) {
            throw ApiException::transport($url, $error !== '' ? $error : 'nieznany błąd curl');
        }

        /** @var array<string, list<string>> $responseHeaders */
        return new ApiResponse($status, (string) $body, $responseHeaders);
    }
}
