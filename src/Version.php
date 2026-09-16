<?php

declare(strict_types=1);

namespace BabelHub\Cli;

/**
 * Wersja CLI — **prawdziwa**, bo `--version`, które kłamie, psuje skrypty.
 *
 * To nie jest teoretyczne zastrzeżenie z planu (1.16): narzędzia tej kategorii potrafią
 * raportować wersję, której nikt nie wydał, a pipeline'y warunkujące kroki od `--version`
 * podejmują wtedy decyzje na podstawie fikcji. Trzymamy jedną stałą, aktualizowaną razem
 * z wydaniem (i pilnowaną testem, który porównuje ją z manifestem pakietu), zamiast wyciągać
 * ją z `composer.lock` albo z danych Git-a, których w obrazie CI może nie być.
 */
final class Version
{
    public const VALUE = '0.1.0-dev';

    /** Identyfikacja klienta w parowaniu: człowiek w panelu ma widzieć, co zatwierdza. */
    public static function clientName(): string
    {
        return sprintf('babelhub-cli/%s (%s; PHP %s)', self::VALUE, PHP_OS_FAMILY, PHP_VERSION);
    }
}
