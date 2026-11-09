<?php

declare(strict_types=1);

namespace BabelHub\Cli\Content;

use InvalidArgumentException;

/**
 * Jedna treść w repozytorium: katalog `<dir>/<key>/` z `meta.json`, `source.html` i `<locale>.html`.
 *
 * Po co katalog, a nie jeden plik JSON z całą treścią: ciało e-maila bywa długie i ma znaczniki,
 * a w JSON-ie każda zmiana to jedna długa linia z `\n` — code review przestaje widzieć, co się
 * zmieniło. Osobne pliki dają zwykły diff linii, a `meta.json` zostaje mały i czytelny.
 *
 * Format jest **tekstem**, nie szablonem wykonywanym przez CLI: `{{name}}` jedzie do serwisu
 * dosłownie, bo to serwis waliduje deklarację zmiennych i renderuje podgląd.
 */
final readonly class ContentItemFile
{
    public const META = 'meta.json';
    public const SOURCE = 'source.html';

    /**
     * @param list<string> $variables
     * @param array<string, string> $variants kod języka => treść
     */
    public function __construct(
        public string $key,
        public string $type,
        public string $source,
        public array $variables,
        public array $variants,
    ) {
    }

    /**
     * @param array<string, mixed> $meta
     * @param array<string, string> $variants
     */
    public static function fromDirectory(string $key, array $meta, string $source, array $variants): self
    {
        $type = $meta['type'] ?? null;
        if (!is_string($type) || $type === '') {
            throw new InvalidArgumentException(sprintf('Treść „%s”: meta.json wymaga pola "type".', $key));
        }

        $variables = [];
        foreach ((array) ($meta['variables'] ?? []) as $variable) {
            if (is_string($variable) && $variable !== '') {
                $variables[] = $variable;
            }
        }

        if (trim($source) === '') {
            throw new InvalidArgumentException(sprintf('Treść „%s”: %s jest puste.', $key, self::SOURCE));
        }

        return new self($key, $type, $source, array_values(array_unique($variables)), $variants);
    }
}
