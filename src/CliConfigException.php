<?php

declare(strict_types=1);

namespace BabelHub\Cli;

use RuntimeException;

/**
 * Błąd **konfiguracji projektu** — plik, który istnieje, ale nie da się z niego pracować.
 *
 * Wydzielony z `RuntimeException` nie dla elegancji, a dla **kodu wyjścia**. Tabela w `docs/cli.md`
 * mówi: `2` = „popraw wywołanie albo konfigurację”, `1` = „awaria narzędzia”. Zepsuty YAML,
 * dwa pliki konfiguracji naraz albo nieznany klucz to pierwsza kategoria — a bez własnego typu
 * leciały jako nieprzewidziany wyjątek i wychodziły kodem `1`, czyli „coś się zepsuło”, bez
 * wskazania, że problem jest w repozytorium.
 *
 * Wzorzec jest ten sam co przy `CliIoException` (awaria lokalna → `1`): typ wyjątku niesie
 * **decyzję o kodzie wyjścia**, a mapowanie stoi w jednym miejscu (`Application::run`), więc
 * żadna komenda nie musi o tym pamiętać — i nowa nie może tego zepsuć.
 */
final class CliConfigException extends RuntimeException
{
}
