<?php

declare(strict_types=1);

namespace BabelHub\Cli\Config;

/**
 * Poświadczenie CLI: adres serwisu + token osobisty, plus to, czego się o nim dowiedzieliśmy
 * (`whoami`), żeby `status` i `push` nie musiały pytać o to przy każdym uruchomieniu.
 *
 * `token` **nigdy** nie trafia do pliku w repozytorium — to cała różnica między `.babelhub.json`
 * (konfiguracja projektu, commitowana) a tym plikiem (poświadczenie, poza repozytorium).
 * Rozdzielenie jest w planie (D4) nazwane wprost, bo pierwszy odruch developera to wklejenie
 * tokenu do pliku, który i tak jest w repo.
 */
final readonly class Credentials
{
    /** @param list<string> $scopes */
    public function __construct(
        public string $apiUrl,
        public string $token,
        public ?string $user = null,
        public ?string $organization = null,
        public array $scopes = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): ?self
    {
        $apiUrl = $data['api_url'] ?? null;
        $token = $data['token'] ?? null;

        if (!is_string($apiUrl) || $apiUrl === '' || !is_string($token) || $token === '') {
            return null;
        }

        $scopes = [];
        foreach ((array) ($data['scopes'] ?? []) as $scope) {
            if (is_string($scope)) {
                $scopes[] = $scope;
            }
        }

        return new self(
            apiUrl: $apiUrl,
            token: $token,
            user: is_string($data['user'] ?? null) ? $data['user'] : null,
            organization: is_string($data['organization'] ?? null) ? $data['organization'] : null,
            scopes: $scopes,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'api_url' => $this->apiUrl,
            'token' => $this->token,
            'user' => $this->user,
            'organization' => $this->organization,
            'scopes' => $this->scopes,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * Tożsamość do wypisania w `whoami` i w komunikatach: nazwa człowieka, a gdy jej nie ma
     * (token z CI), przynajmniej identyfikator tokenu skrócony do pierwszych znaków.
     * **Nigdy cały token** — logi CI bywają publiczne.
     */
    public function describe(): string
    {
        $who = $this->user ?? $this->maskedToken();

        return $this->organization !== null ? sprintf('%s @ %s', $who, $this->organization) : $who;
    }

    public function maskedToken(): string
    {
        $prefix = substr($this->token, 0, 12);

        return $prefix . '…';
    }
}
