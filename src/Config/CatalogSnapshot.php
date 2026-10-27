<?php

declare(strict_types=1);

namespace BabelHub\Cli\Config;

/** Proof of the normalized, managed values in the project's actual output files. */
final readonly class CatalogSnapshot
{
    /** @param list<string> $keys */
    public function __construct(public array $keys, public string $hash, public string $layout)
    {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): ?self
    {
        $keys = $data['keys'] ?? null;
        if (!is_array($keys) || !array_is_list($keys)) {
            return null;
        }
        foreach ($keys as $key) {
            if (!is_string($key) || $key === '') {
                return null;
            }
        }
        if (count(array_unique($keys)) !== count($keys)) {
            return null;
        }
        foreach (['hash', 'layout'] as $field) {
            if (!is_string($data[$field] ?? null) || preg_match('/^[a-f0-9]{64}$/', $data[$field]) !== 1) {
                return null;
            }
        }
        return new self($keys, $data['hash'], $data['layout']);
    }

    /** @return array{keys: list<string>, hash: string, layout: string} */
    public function toArray(): array
    {
        return ['keys' => $this->keys, 'hash' => $this->hash, 'layout' => $this->layout];
    }
}
