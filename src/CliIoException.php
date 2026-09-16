<?php

declare(strict_types=1);

namespace BabelHub\Cli;

use RuntimeException;

/**
 * Błąd **lokalny** — dysk, uprawnienia, nieistniejący katalog. Kod wyjścia `1`, nie `2`.
 *
 * Rozdzielenie od `RuntimeException` (którym CLI sygnalizuje błędy użycia i konfiguracji) nie
 * jest kosmetyką: `docs/cli.md` obiecuje, że `2` znaczy „popraw wywołanie”, a `1` — „coś padło
 * lokalnie”. Zbyt szerokie `catch (\RuntimeException)` w komendach zamieniało awarię zapisu
 * („Nie mogę utworzyć katalogu …”) na `2`, czyli pipeline szukałby błędu w argumentach,
 * których nie ma, zamiast zgłosić problem ze środowiskiem.
 */
final class CliIoException extends RuntimeException
{
}
