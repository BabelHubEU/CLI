<?php

declare(strict_types=1);

namespace BabelHub\Cli\Config;

use InvalidArgumentException;

/** Public identity of a catalog's server and tenant, independent of credentials. */
final readonly class CatalogSource
{
    public string $apiUrl;

    public function __construct(string $apiUrl, public string $organization)
    {
        $parts = parse_url($apiUrl);
        if (
            $parts === false || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || trim($organization) === ''
        ) {
            throw new InvalidArgumentException('Nie można ustalić tożsamości źródła katalogów. Użyj adresu HTTP(S) bez danych logowania, query i fragmentu.');
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $suffix = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80) ? '' : ':' . $port;
        $this->apiUrl = $scheme . '://' . $host . $suffix . rtrim($parts['path'] ?? '', '/');
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): ?self
    {
        if (!is_string($data['api_url'] ?? null) || !is_string($data['organization'] ?? null)) {
            return null;
        }
        try {
            return new self($data['api_url'], $data['organization']);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** @return array{api_url: string, organization: string} */
    public function toArray(): array
    {
        return ['api_url' => $this->apiUrl, 'organization' => $this->organization];
    }

    public function equals(self $other): bool
    {
        return $this->apiUrl === $other->apiUrl && $this->organization === $other->organization;
    }
}
