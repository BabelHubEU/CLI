<?php

declare(strict_types=1);

namespace BabelHub\Cli\Content;

use BabelHub\Cli\CliIoException;
use FilesystemIterator;

/**
 * Odczyt i zapis treści w repozytorium (`content/` domyślnie).
 *
 * Klucz treści to **nazwa katalogu**, więc walidację klucza robi serwis — CLI nie powtarza
 * reguły, żeby nie mogła się rozjechać. Tu pilnujemy tylko rzeczy lokalnej: że jest co wysłać
 * i że zapis nie wyjdzie poza katalog treści.
 */
final readonly class ContentDirectory
{
    public function __construct(public string $path)
    {
    }

    /**
     * @return list<ContentItemFile> posortowane po kluczu
     *
     * @throws CliIoException gdy katalogu nie ma albo wpis jest nieczytelny
     */
    public function read(): array
    {
        if (!is_dir($this->path)) {
            return [];
        }

        $keys = [];
        /** @var \SplFileInfo $entry */
        foreach (new FilesystemIterator($this->path, FilesystemIterator::SKIP_DOTS) as $entry) {
            if ($entry->isDir()) {
                $keys[] = $entry->getFilename();
            }
        }
        sort($keys);

        $items = [];
        foreach ($keys as $key) {
            $directory = $this->path . '/' . $key;
            $metaPath = $directory . '/' . ContentItemFile::META;
            $sourcePath = $directory . '/' . ContentItemFile::SOURCE;
            if (!is_file($metaPath) || !is_file($sourcePath)) {
                throw new CliIoException(sprintf('Treść „%s”: brak %s albo %s.', $key, ContentItemFile::META, ContentItemFile::SOURCE));
            }

            $meta = json_decode((string) file_get_contents($metaPath), true);
            if (!is_array($meta)) {
                throw new CliIoException(sprintf('Treść „%s”: %s nie jest poprawnym JSON-em.', $key, ContentItemFile::META));
            }

            $variants = [];
            /** @var \SplFileInfo $file */
            foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $file) {
                $name = $file->getFilename();
                if (!$file->isFile() || $name === ContentItemFile::META || $name === ContentItemFile::SOURCE) {
                    continue;
                }
                if (!str_ends_with($name, '.html')) {
                    continue;
                }
                $variants[substr($name, 0, -5)] = (string) file_get_contents($file->getPathname());
            }
            ksort($variants);

            $items[] = ContentItemFile::fromDirectory(
                $key,
                $meta,
                (string) file_get_contents($sourcePath),
                $variants,
            );
        }

        return $items;
    }

    /**
     * Zapis treści pobranej z serwisu. Nadpisuje tylko pliki, które należą do formatu —
     * nie kasuje niczego, czego CLI nie utworzyło (np. README w katalogu treści).
     */
    public function writeVariants(ContentItemFile $item): void
    {
        $directory = $this->path . '/' . $item->key;
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new CliIoException(sprintf('Nie mogę utworzyć katalogu %s.', $directory));
        }

        $this->put($directory . '/' . ContentItemFile::META, json_encode([
            'type' => $item->type,
            'variables' => $item->variables,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        $this->put($directory . '/' . ContentItemFile::SOURCE, $item->source);

        foreach ($item->variants as $locale => $body) {
            $this->put($directory . '/' . $locale . '.html', $body);
        }
    }

    private function put(string $path, string $contents): void
    {
        if (@file_put_contents($path, $contents) === false) {
            throw new CliIoException(sprintf('Nie mogę zapisać %s.', $path));
        }
    }
}
