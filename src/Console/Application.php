<?php

declare(strict_types=1);

namespace BabelHub\Cli\Console;

use BabelHub\Cli\Config\CredentialsStore;
use BabelHub\Cli\Config\ProjectConfigStore;
use BabelHub\Cli\CliConfigException;
use BabelHub\Cli\Console\Command\DiffCommand;
use BabelHub\Cli\Console\Command\InitCommand;
use BabelHub\Cli\Console\Command\LoginCommand;
use BabelHub\Cli\Console\Command\LogoutCommand;
use BabelHub\Cli\Console\Command\PullCommand;
use BabelHub\Cli\Console\Command\PushCommand;
use BabelHub\Cli\Console\Command\ScanCommand;
use BabelHub\Cli\Console\Command\StatusCommand;
use BabelHub\Cli\Console\Command\SyncCommand;
use BabelHub\Cli\Console\Command\ValidateCommand;
use BabelHub\Cli\Console\Command\WhoamiCommand;
use BabelHub\Cli\ExitCode;
use BabelHub\Cli\Http\ApiClient;
use BabelHub\Cli\Http\ApiClientInterface;
use BabelHub\Cli\Output\OutputMode;
use BabelHub\Cli\Output\Printer;
use BabelHub\Cli\Output\SplitOutput;
use BabelHub\Cli\Scan\PhpTwigScanner;
use BabelHub\Cli\Version;
use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Console\Exception\ExceptionInterface as ConsoleException;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Aplikacja konsolowa CLI — **osobny pakiet, nie konsola serwisu**.
 *
 * Rozróżnienie jest istotne, bo `bin/console` obsługuje serwis (migracje, import, retencja),
 * a `babelhub` jest klientem, który z serwisem rozmawia po HTTP. Gdyby dzieliły kod, CLI
 * instalowane na laptopie developera ciągnęłoby całe Doctrine i konfigurację bazy —
 * a to jest dokładnie ten rodzaj zależności, przez który narzędzie przestaje być wygodne.
 *
 * Opcje globalne (`--output`, `--timeout`, `--api-url`, `--token`, `--profile`) są wspólne dla
 * wszystkich komend, bo każda z nich rozmawia z tym samym serwisem tym samym poświadczeniem.
 * Trzymanie ich w jednym miejscu sprawia, że nowa komenda dostaje je za darmo — a nie że jedna
 * z pięciu zapomina o `--timeout` i „wisi” w CI.
 */
final class Application
{
    public const NAME = 'BabelHub CLI';

    /**
     * @param string|null $credentialsPath ścieżka **pliku** poświadczeń; `null` = domyślna lokalizacja
     *   użytkownika. Parametr jest tu (a nie odczytywany w środku), bo testy CLI muszą wskazać
     *   własny katalog tymczasowy — inaczej `babelhub logout` w testach kasowałby poświadczenie
     *   prawdziwego użytkownika, na którego maszynie akurat chodzi bramka.
     * @param callable(string, ?string, float): ApiClientInterface|null $clientFactory fabryka klienta
     *   HTTP; trzeci parametr to limit oczekiwania z `--timeout`. W testach podstawiamy atrapę,
     *   żeby sprawdzać **zachowanie CLI** (kody wyjścia, pliki, rozdział strumieni) bez sieci —
     *   patrz `tests/Cli/`
     */
    public static function run(
        ?string $credentialsPath = null,
        ?OutputInterface $stdout = null,
        ?OutputInterface $stderr = null,
        ?InputInterface $input = null,
        $clientFactory = null,
    ): int {
        $store = new CredentialsStore($credentialsPath ?? CredentialsStore::defaultPath());
        if ($stdout === null) {
            $console = new ConsoleOutput();
            $output = $console;
            $errorOutput = $console->getErrorOutput();
        } else {
            $errorOutput = $stderr ?? $stdout;
            $output = new SplitOutput($stdout, $errorOutput);
        }

        $application = new SymfonyApplication(self::NAME, Version::VALUE);
        $application->getDefinition()->addOptions(self::globalOptions());
        // **`setAutoExit(false)` jest obowiązkowe**, a nie kosmetyczne: Symfony Console domyślnie
        // woła `exit()` na końcu `run()`. W `bin/babelhub` to nie przeszkadza (i tak kończymy
        // proces), ale w testach **zabija PHPUnit w połowie przebiegu** — bez podsumowania,
        // z kodem 0, czyli dokładnie w sposób, który wygląda jak sukces. Kod wyjścia wraca
        // więc do wywołującego, a `exit` robi wyłącznie wejście CLI.
        $application->setAutoExit(false);
        // **`setCatchExceptions(false)` z tego samego powodu co wyżej, ale o skutku dla kontraktu.**
        // Symfony sam łapie wyjątki i zwraca `1`: błąd użycia (`--output=xml`, `--timeout=abc`)
        // wychodził więc kodem „awaria narzędzia”, a nie `2` („popraw wywołanie”), i dodatkowo
        // wysypywał na ekran stos wywołań. Łapiemy sami — jedno miejsce na całe CLI, więc żadna
        // komenda nie musi o tym pamiętać, a nowa nie może tego zepsuć.
        $application->setCatchExceptions(false);
        // `addCommand()`, a nie `add()`: to drugie jest od Symfony 7.4 przestarzałe i psuje
        // bramkę `failOnWarning` — a ostrzeżenie w CLI, które użytkownik widzi przy każdym
        // uruchomieniu, jest dokładnie tym rodzajem szumu, przez który przestaje się czytać logi.
        $session = new ApiSession($store, $clientFactory);
        $scanner = new PhpTwigScanner();
        $application->addCommand(new LoginCommand($store, new Browser(), $clientFactory));
        $application->addCommand(new LogoutCommand($store));
        $application->addCommand(new WhoamiCommand($store, $clientFactory));
        $application->addCommand(new InitCommand(new ProjectConfigStore(self::startDirectory()), $session));
        $application->addCommand(new ScanCommand($scanner));
        $application->addCommand(new PushCommand($session, $scanner));
        $application->addCommand(new PullCommand($session));
        $application->addCommand(new SyncCommand($session, $scanner));
        $application->addCommand(new StatusCommand($session));
        $application->addCommand(new ValidateCommand($session));
        $application->addCommand(new DiffCommand($session));

        try {
            return $application->run($input ?? new ArgvInput(), $output);
        } catch (CliConfigException $e) {
            // Błąd konfiguracji projektu to `2` („popraw plik”), a nie `1` („awaria narzędzia”).
            // Bez tego typu zepsuty YAML wychodził kodem `1`, czyli bez wskazania, że problem
            // jest w repozytorium, a nie w narzędziu.
            $errorOutput->writeln('<error>' . $e->getMessage() . '</error>');

            return ExitCode::Usage->value;
        } catch (\InvalidArgumentException | ConsoleException $e) {
            // Błąd użycia: `2`. Dwa typy, bo **ta sama pomyłka człowieka rzuca raz jedno, raz drugie**:
            // nieznany tryb wyjścia to nasz `InvalidArgumentException`, a nieznana opcja
            // (`whoami --directory=/tmp`) to `Symfony\...\Exception\RuntimeException` z `ArgvInput`.
            // Wyszło to dopiero na żywym CLI — w testach `ArrayInput` rzuca `InvalidArgumentException`,
            // więc „nie ma takiej opcji” wychodziło kodem `2` w teście i `1` u użytkownika.
            // Wszystkie wyjątki Symfony Console mają wspólny znacznik `ConsoleException`, więc
            // łapiemy je jednym typem, zamiast zgadywać klasy po komunikatach.
            $errorOutput->writeln('<error>' . $e->getMessage() . '</error>');

            return ExitCode::Usage->value;
        } catch (\Throwable $e) {
            // Wszystko inne to awaria narzędzia: `1`. Stos wywołań tylko na życzenie (`-v`),
            // bo w CI nikt go nie czyta, a sekret z nagłówka potrafi w nim zostać.
            $errorOutput->writeln('<error>' . $e->getMessage() . '</error>');
            if ($output->isVerbose()) {
                $errorOutput->writeln($e->getTraceAsString());
            }

            return ExitCode::Failure->value;
        }
    }

    /**
     * Drukarka dla komendy: tryb z `--output`, wynik na `stdout`, reszta na `stderr`.
     *
     * Rozdział strumieni bierzemy z `ConsoleOutput` (jedyna implementacja, która ma osobne
     * wyjście błędów). Gdy test podstawi własne wyjście, oba trafiają w to samo miejsce —
     * i wtedy widać w nim całość, co jest dokładnie tym, czego test potrzebuje.
     */
    public static function printer(InputInterface $input, OutputInterface $output): Printer
    {
        $requested = $input->getOption('output');
        // Nieznana wartość to błąd użycia, a nie powrót do `text`: skrypt parsujący JSON-a
        // dostałby wtedy zdania dla człowieka i „działałby”, dopóki ktoś nie spojrzy w wynik.
        $mode = OutputMode::tryParse(is_string($requested) ? $requested : OutputMode::Text->value);
        if ($mode === null) {
            throw new \InvalidArgumentException(sprintf(
                'Nieznany tryb wyjścia „%s”. Dozwolone: %s.',
                is_string($requested) ? $requested : '',
                implode(', ', OutputMode::values()),
            ));
        }

        $stderr = $output instanceof ConsoleOutput ? $output->getErrorOutput() : $output;

        return new Printer($output, $stderr, $mode);
    }

    /**
     * Limit oczekiwania z `--timeout` — **odrzucamy śmieci, nie podstawiamy domyślnej wartości**.
     *
     * Cicha podmiana jest tu groźniejsza niż w innych opcjonalnych flagach: `--timeout=60s`
     * (z jednostką, czego ludzie próbują) dawałoby 15 sekund i „losowe” zrywanie długich żądań,
     * a przyczyny nie byłoby widać w logu. Limit „700 sekund i nie da się skrócić” to zresztą
     * jedna z udokumentowanych bolączek tej kategorii narzędzi (1.16), więc nasza odpowiedź musi
     * być sprawdzalna: liczba sekund większa od zera albo błąd użycia z kodem `2`.
     *
     * Metoda jest `public static`, bo wołają ją komendy przy budowaniu klienta — limit należy
     * do **warstwy wejścia**, a nie do transportu, i jedno miejsce na jego odczyt znaczy, że
     * nowa komenda nie może o nim zapomnieć (patrz `ApiSession::client()`).
     */
    public static function timeoutSeconds(InputInterface $input): float
    {
        $requested = $input->getOption('timeout');
        if ($requested === null || $requested === '') {
            return ApiClient::DEFAULT_TIMEOUT_SECONDS;
        }

        if (is_numeric($requested) && (float) $requested > 0.0) {
            return (float) $requested;
        }

        throw new \InvalidArgumentException(sprintf(
            'Nieznany limit czasu „%s”. Podaj liczbę sekund większą od zera (domyślnie %s).',
            is_scalar($requested) ? (string) $requested : get_debug_type($requested),
            (string) ApiClient::DEFAULT_TIMEOUT_SECONDS,
        ));
    }

    /**
     * Katalog, od którego szukamy `.babelhub.json` — czyli tam, gdzie człowiek stoi.
     *
     * Osobna metoda, bo `getcwd()` bywa `false` (usunięty katalog roboczy), a wtedy `scan`
     * i `push` mają powiedzieć „nie wiem, gdzie jestem”, a nie szukać konfiguracji w korzeniu
     * systemu plików.
     */
    private static function startDirectory(): string
    {
        $directory = getcwd();

        return $directory === false ? '.' : $directory;
    }

    /** @return list<InputOption> */
    private static function globalOptions(): array
    {
        return [
            new InputOption(
                'output',
                null,
                InputOption::VALUE_REQUIRED,
                sprintf('Wyjście: %s (wynik na stdout, reszta na stderr).', implode('|', OutputMode::values())),
                OutputMode::Text->value,
            ),
            new InputOption('api-url', null, InputOption::VALUE_REQUIRED, 'Adres serwisu (domyślnie z BABELHUB_API_URL albo z zapisanego poświadczenia).'),
            new InputOption('token', null, InputOption::VALUE_REQUIRED, 'Token osobisty — dla CI i kont serwisowych; lokalnie użyj parowania.'),
            new InputOption('profile', null, InputOption::VALUE_REQUIRED, 'Nazwa profilu poświadczenia.', CredentialsStore::DEFAULT_PROFILE),
            new InputOption(
                'timeout',
                null,
                InputOption::VALUE_REQUIRED,
                'Limit oczekiwania na odpowiedź w sekundach.',
                (string) ApiClient::DEFAULT_TIMEOUT_SECONDS,
            ),
        ];
    }
}
