<?php

declare(strict_types=1);

namespace BabelHub\Cli\Config;

use DateTimeImmutable;

/**
 * Trzy rzeczy, które trzeba pamiętać między pierwszym a drugim poleceniem parowania:
 * sekret terminala, kod pokazany człowiekowi i adres, który ma otworzyć.
 *
 * `deviceCode` **nie jest** tu wygodą, a koniecznością — patrz `PendingPairingStore`.
 */
final readonly class PendingPairing
{
    public function __construct(
        public string $deviceCode,
        public string $userCode,
        public string $browserUrl,
        public string $apiUrl,
        public DateTimeImmutable $expiresAt,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): ?self
    {
        $deviceCode = $data['device_code'] ?? null;
        $userCode = $data['user_code'] ?? null;
        $browserUrl = $data['browser_url'] ?? null;
        $apiUrl = $data['api_url'] ?? null;
        $expiresAt = $data['expires_at'] ?? null;

        if (!is_string($deviceCode) || $deviceCode === '' || !is_string($userCode) || $userCode === '') {
            return null;
        }

        $expiry = is_string($expiresAt) ? new DateTimeImmutable($expiresAt) : new DateTimeImmutable('+10 minutes');

        return new self(
            deviceCode: $deviceCode,
            userCode: $userCode,
            browserUrl: is_string($browserUrl) ? $browserUrl : '',
            apiUrl: is_string($apiUrl) ? $apiUrl : '',
            expiresAt: $expiry,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'device_code' => $this->deviceCode,
            'user_code' => $this->userCode,
            'browser_url' => $this->browserUrl,
            'api_url' => $this->apiUrl,
            'expires_at' => $this->expiresAt->format(DATE_ATOM),
        ];
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
    }
}
