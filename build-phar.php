#!/usr/bin/env php
<?php

/**
 * Buduje `babelhub.phar` — binarkę CLI bez Composera.
 *
 * ```bash
 * php -d phar.readonly=0 build-phar.php            # → babelhub.phar w katalogu głównym
 * php -d phar.readonly=0 build-phar.php /tmp/x.phar # → pod wskazaną ścieżką
 * php babelhub.phar --version                       # sprawdzenie, że działa
 * ```
 *
 * `-d phar.readonly=0` jest **wymagane**: PHP domyślnie nie pozwala zapisywać archiwów PHAR
 * (dokładnie po to, żeby przypadkowy kod nie podmienił binarki). Budowanie ma być świadomym
 * krokiem, więc skrypt nie próbuje tego obchodzić — mówi wprost, czego brakuje.
 *
 * Skrypt leży w **korzeniu** repozytorium i zakłada, że `vendor/` jest obok (`composer install`).
 * W wersji z monorepo leżał w `cli/` i sięgał piętro wyżej do vendora serwisu — po wydzieleniu
 * CLI ma własny vendor, więc obie ścieżki się skróciły.
 */

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use BabelHub\Cli\Build\PharBuilder;

$root = __DIR__;
$output = $argv[1] ?? $root . '/' . PharBuilder::ARCHIVE_NAME;

try {
    $builder = new PharBuilder($root, $output);
    $builder->build();
} catch (Throwable $exception) {
    fwrite(STDERR, 'Nie udało się zbudować binarki: ' . $exception->getMessage() . "\n");
    exit(1);
}

$size = is_file($output) ? (int) filesize($output) : 0;

fwrite(STDOUT, sprintf(
    "Zbudowane: %s (%d KB, %d pakietów zależności)\n",
    $output,
    intdiv($size, 1024),
    count($builder->dependencyClosure()),
));
fwrite(STDOUT, "Sprawdź: php {$output} --version\n");

exit(0);
