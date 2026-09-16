<?php

declare(strict_types=1);

namespace BabelHub\Cli\Config;

use BabelHub\Cli\CliIoException;

/**
 * Zapis i odczyt `.babelhub.lock.json`.
 *
 * Zapis jest atomowy (plik tymczasowy + `rename`), tak jak konfiguracji projektu i plików
 * językowych: lock opisuje **stan repozytorium**, więc przerwany zapis zostawiłby plik,
 * który mówi „wersja 12”, gdy pliki są z wersji 11 — czyli dokładnie tę rozbieżność, którą
 * `pull --check` ma wykrywać, tylko wprowadzoną przez nas.
 */
final class CatalogLockStore
{
    public function __construct(private readonly string $root)
    {
    }

    public function path(): string
    {
        return rtrim($this->root, '/\\') . '/' . CatalogLock::FILE_NAME;
    }

    public function load(): ?CatalogLock
    {
        $path = $this->path();
        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? CatalogLock::fromArray($decoded) : null;
    }

    public function save(CatalogLock $lock): void
    {
        $encoded = json_encode(
            $lock->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        $path = $this->path();
        $temporary = $path . '.tmp';

        if (file_put_contents($temporary, $encoded . "\n") === false) {
            throw new CliIoException(sprintf('Nie udało się zapisać %s.', $path));
        }

        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new CliIoException(sprintf('Nie udało się podmienić %s.', $path));
        }
    }
}
