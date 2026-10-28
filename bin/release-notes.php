<?php

declare(strict_types=1);

use BabelHub\Cli\Build\ReleaseNotes;
use BabelHub\Cli\Version;

require dirname(__DIR__) . '/vendor/autoload.php';

try {
    $tag = $argv[1] ?? '';
    if ($tag !== 'v' . Version::VALUE || str_ends_with(Version::VALUE, '-dev')) {
        throw new InvalidArgumentException('The release tag must match Version::VALUE and must not be a development version.');
    }
    $markdown = file_get_contents(dirname(__DIR__) . '/CHANGELOG.md');
    if ($markdown === false) {
        throw new RuntimeException('Cannot read CHANGELOG.md.');
    }
    fwrite(STDOUT, ReleaseNotes::forVersion($markdown, Version::VALUE));
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
