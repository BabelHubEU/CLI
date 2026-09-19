<?php

declare(strict_types=1);

namespace BabelHub\Cli;

/**
 * Wersja CLI — **prawdziwa**, bo `--version`, które kłamie, psuje skrypty.
 *
 * To nie jest teoretyczne zastrzeżenie z planu (1.16): narzędzia tej kategorii potrafią
 * raportować wersję, której nikt nie wydał, a pipeline'y warunkujące kroki od `--version`
 * podejmują wtedy decyzje na podstawie fikcji. Trzymamy jedną stałą, zamiast wyciągać ją
 * z `composer.lock` albo z danych Git-a, których w obrazie CI może nie być.
 *
 * **Pilnuje jej `tests/Cli/VersionTest.php` — porównaniem z tagiem na HEAD, nie z manifestem**
 * (manifest nie ma pola `version`, bo Composer i Packagist wyliczają je właśnie z tagu).
 * Konsekwencja, którą trzeba znać przy wydaniu: otagowany commit musi mieć stałą równą tagowi,
 * a nieotagowany — sufiks przedwydaniowy. Stąd procedura: podbij stałą do wersji wydania, zrób
 * commit i **tag na tym samym commicie**, a zaraz po wydaniu podbij do kolejnej `-dev`.
 */
final class Version
{
    public const VALUE = '0.1.2-dev';

    /** Identyfikacja klienta w parowaniu: człowiek w panelu ma widzieć, co zatwierdza. */
    public static function clientName(): string
    {
        return sprintf('babelhub-cli/%s (%s; PHP %s)', self::VALUE, PHP_OS_FAMILY, PHP_VERSION);
    }
}
