<?php

declare(strict_types=1);

namespace BabelHub\Cli\Files;

use BabelHub\Cli\Config\CatalogSnapshot;
use BabelHub\Cli\Config\ProjectConfig;
use JsonException;
use RuntimeException;

/** Reads only managed values, in the output files selected by the project layout. */
final class CatalogSnapshotReader
{
    public function matches(CatalogSnapshot $snapshot, ProjectConfig $config, string $root, string $locale): bool
    {
        $actual = $this->capture($config, $root, $locale, $snapshot->keys);
        return $actual !== null && $actual->layout === $snapshot->layout && $actual->hash === $snapshot->hash;
    }

    /** @param list<string> $keys */
    public function capture(ProjectConfig $config, string $root, string $locale, array $keys): ?CatalogSnapshot
    {
        sort($keys, SORT_STRING);
        $file = new LocaleFile($config->fileFormat);
        $files = [];
        $values = [];
        try {
            foreach ($keys as $key) {
                $namespace = $config->usesNamespaces() ? (str_contains($key, '.') ? (string) strstr($key, '.', true) : $key) : null;
                $path = $config->fileFor($locale, $root, $namespace);
                if (!is_file($path) || !is_readable($path)) {
                    return null;
                }
                $files[$path] ??= $file->read($path, $config->fileRootKey);
                if (!array_key_exists($key, $files[$path])) {
                    return null;
                }
                $values[$key] = $files[$path][$key];
            }
            return new CatalogSnapshot(
                $keys,
                hash('sha256', json_encode($values, JSON_THROW_ON_ERROR)),
                hash('sha256', json_encode([
                    'pattern' => $config->filePattern,
                    'format' => $config->fileFormat,
                    'root_key' => $config->fileRootKey,
                    'sort' => $config->downloadSort,
                    'nested' => $config->downloadWriteNested,
                    'hook' => $config->downloadPostProcessCommand,
                ], JSON_THROW_ON_ERROR)),
            );
        } catch (RuntimeException | JsonException) {
            return null;
        }
    }
}
