<?php

declare(strict_types=1);

namespace BabelHub\Cli\Console;

use BabelHub\Cli\Config\Credentials;
use BabelHub\Cli\Config\CredentialsStore;
use BabelHub\Cli\Http\ApiClient;
use BabelHub\Cli\Http\ApiClientInterface;

/**
 * Jedno miejsce, w którym komenda dostaje poświadczenie i klienta HTTP.
 *
 * Powstało z konkretnego powodu: każda nowa komenda (`push`, `pull`, `status`, `validate`)
 * potrzebuje dokładnie tych samych pięciu linii — rozwiąż poświadczenie z kolejności źródeł,
 * zbuduj klienta, a gdy poświadczenia nie ma, powiedz to zdaniem, które mówi, co zrobić.
 * Rozproszone po komendach rozjeżdżają się przy pierwszej zmianie: jedna zapomni o fladze
 * `--token`, druga o komunikacie i użytkownik dostanie `401` zamiast „zaloguj się”.
 *
 * Fabryka klienta jest wstrzykiwana, bo **testy CLI nie mogą wychodzić do sieci** — podmieniamy
 * ją na atrapę i sprawdzamy zachowanie (kody wyjścia, pliki, strumienie), a nie HTTP.
 */
final readonly class ApiSession
{
    /** `\Closure`, a nie `callable`: właściwość `readonly` **musi** mieć typ (PHP 8.3). */
    private ?\Closure $clientFactory;

    /** @param callable(string, ?string, float): ApiClientInterface|null $clientFactory */
    public function __construct(
        private CredentialsStore $store,
        ?callable $clientFactory = null,
    ) {
        $this->clientFactory = $clientFactory !== null ? \Closure::fromCallable($clientFactory) : null;
    }

    public function credentials(
        ?string $tokenFlag = null,
        ?string $apiUrlFlag = null,
        ?string $profile = null,
    ): ?Credentials {
        return $this->store->resolve(
            tokenFromFlag: $tokenFlag,
            apiUrlFromFlag: $apiUrlFlag,
            profile: $profile,
        );
    }

    /**
     * Klient do rozmowy z serwisem. `$timeoutSeconds` **musi** tu przyjść od komendy, bo tylko ona
     * ma `InputInterface` — a `--timeout` był wcześniej czytany wyłącznie przez martwą metodę
     * `Application::apiClient()`, więc flaga nie robiła nic: każda komenda budowała klienta
     * z domyślnym limitem i „konfigurowalny krótki timeout” z planu (1.16) był nieprawdą.
     */
    public function client(Credentials $credentials, ?float $timeoutSeconds = null): ApiClientInterface
    {
        return $this->clientFor($credentials->apiUrl, $credentials->token, $timeoutSeconds);
    }

    public function clientFor(string $apiUrl, ?string $token, ?float $timeoutSeconds = null): ApiClientInterface
    {
        $timeout = $timeoutSeconds ?? ApiClient::DEFAULT_TIMEOUT_SECONDS;

        if ($this->clientFactory !== null) {
            return ($this->clientFactory)($apiUrl, $token, $timeout);
        }

        return new ApiClient($apiUrl, $token, timeoutSeconds: $timeout);
    }

    public function store(): CredentialsStore
    {
        return $this->store;
    }

    /**
     * Zdanie dla człowieka, który nie ma poświadczenia — z **drogą wyjścia**, nie samym faktem.
     */
    public function missingCredentialsHint(): string
    {
        return sprintf(
            'Brak poświadczenia. Uruchom `babelhub login` (parowanie w przeglądarce) albo ustaw %s dla CI. Plik poświadczeń: %s.',
            CredentialsStore::ENV_TOKEN,
            $this->store->path(),
        );
    }
}
