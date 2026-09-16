<?php

declare(strict_types=1);

namespace BabelHub\Cli\Console\Command;

use BabelHub\Cli\Application\CliValidate;
use BabelHub\Cli\Checks\CheckName;
use BabelHub\Cli\Checks\CheckSeverity;
use BabelHub\Cli\Checks\HardcodedStringsScanner;
use BabelHub\Cli\Config\ProjectConfig;
use BabelHub\Cli\Config\ProjectConfigStore;
use BabelHub\Cli\Console\ApiSession;
use BabelHub\Cli\Console\Application;
use BabelHub\Cli\ExitCode;
use BabelHub\Cli\Output\Printer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `babelhub validate` — **bramka CI**: kod wyjścia `0`, gdy lokalizacja jest w porządku,
 * `6`, gdy kontrola coś znalazła (pozycja 1.7 planu, rozszerzona w 1.14 o cztery nazwane
 * kontrole).
 *
 * To jest funkcja, za którą płaci się najchętniej, i jednocześnie miejsce, w którym najłatwiej
 * stracić zaufanie — dlatego trzy rzeczy są tu zrobione wprost:
 *
 * 1. **Kod wyjścia jest jednoznaczny.** `0` znaczy „sprawdzone i czyste”, `6` — „sprawdzone
 *    i coś znalazłem”, a `3`/`4`/`5` — „nie udało się sprawdzić”. Pipeline rozróżnia te stany,
 *    bo „nie umiem sprawdzić” nie może wyglądać jak „jest dobrze” (dokładnie ten błąd popełnia
 *    `crowdin download`, wychodząc zerem mimo awarii).
 * 2. **Próg ważności jest jawny** (`--fail-on=error|warning`). Domyślnie blokuje tylko `error`,
 *    bo fałszywy alarm blokujący merge uczy ludzi obchodzenia bramki — a wtedy kontrola
 *    przestaje istnieć. Zespół, który chce ostrzejszej bramki, mówi to wprost w CI.
 * 3. **Kontrola ma nazwę** (`--check=quality|coverage|acceptance|hardcoded-strings`). Nazwa
 *    trafia do kroku w CI, więc developer widzi, **co** blokuje merge, nie otwierając logu —
 *    a zestaw domyślny deklaruje repozytorium (`checks.run` w `.babelhub.yml`), bo to ono wie,
 *    czym jest jego bramka.
 *
 * Komenda jest **cienka** i taka ma zostać: czyta flagi, ładuje konfigurację, rozwiązuje
 * poświadczenie (tylko wtedy, gdy kontrola naprawdę idzie do serwisu) i oddaje sterowanie
 * do `CliValidate`, gdzie siedzi cała logika kontroli i werdyktu.
 */
#[AsCommand(name: 'validate', description: 'Uruchamia nazwane kontrole jakości i zwraca kod wyjścia jako bramkę CI (0 = OK, 6 = znaleziono problemy)')]
final class ValidateCommand extends Command
{
    /**
     * Progi `--fail-on` — **lista zamknięta w jednym miejscu**.
     *
     * Stała jest `public`, bo czyta ją test kontraktu fragmentów CI
     * (`tests/Contract/CiFragmentTest`): fragment z `--fail-on=eror` ma przewrócić test
     * w repozytorium BabelHub, a nie pipeline klienta. Duplikowanie tej listy w teście byłoby
     * dokładnie tym, przed czym ten test broni — drugim źródłem prawdy, które się rozjeżdża.
     */
    public const THRESHOLDS = ['error', 'warning'];

    public function __construct(
        private readonly ApiSession $session,
        private readonly HardcodedStringsScanner $scanner = new HardcodedStringsScanner(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('check', null, InputOption::VALUE_REQUIRED, sprintf(
                'Która kontrola: %s. Domyślnie zestaw z "checks.run" (%s), a bez niego %s.',
                implode(', ', CheckName::names()),
                ProjectConfig::FILE_NAME_YAML,
                CheckName::Quality->value,
            ))
            ->addOption('locale', null, InputOption::VALUE_REQUIRED, 'Sprawdź jeden język (domyślnie wszystkie języki projektu).')
            ->addOption('fail-on', null, InputOption::VALUE_REQUIRED, sprintf(
                'Od jakiej wagi kończyć kodem 6: %s (domyślnie: checks.fail_on z konfiguracji, a bez tego %s).',
                implode(' albo ', self::THRESHOLDS),
                self::THRESHOLDS[0],
            ))
            ->addOption('directory', null, InputOption::VALUE_REQUIRED, 'Katalog projektu (domyślnie: szukaj .babelhub.json w górę).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $printer = Application::printer($input, $output);

        // Znaczenie `--check` sprawdzamy **przed** wczytaniem konfiguracji i przed żądaniem:
        // literówka w nazwie kontroli nie ma po co kosztować wywołania API. Lista nazw pochodzi
        // z `CheckName`, czyli z tego samego miejsca co walidacja `checks.run`.
        $check = null;
        $requested = $this->stringOption($input, 'check');
        if ($requested !== null) {
            $check = CheckName::tryFrom(mb_strtolower($requested));
            if ($check === null) {
                $printer->error(sprintf('Nieznana kontrola --check. Dozwolone: %s.', implode(', ', CheckName::names())));

                return ExitCode::Usage->value;
            }
        }

        $config = $this->loadConfig($input, $printer);
        if ($config === null) {
            return ExitCode::Usage->value;
        }

        // Kolejność źródeł: flaga → `checks.fail_on` w repozytorium → domyślne `error`.
        // Flaga wygrywa, więc zespół może zaostrzyć bramkę na jedno uruchomienie (np. w nocy),
        // nie zmieniając pliku i nie wymuszając tego na wszystkich.
        $failOn = mb_strtolower($this->stringOption($input, 'fail-on') ?? $config->checksFailOn);
        if (!in_array($failOn, self::THRESHOLDS, true)) {
            $printer->error(sprintf('Nieznana wartość --fail-on. Dozwolone: %s.', implode(', ', self::THRESHOLDS)));

            return ExitCode::Usage->value;
        }

        // Ta sama kolejność źródeł dla zestawu kontroli: `--check` jest decyzją na jedno
        // uruchomienie, a `checks.run` mówi, jak zespół sprawdza się na co dzień.
        $checks = $check !== null ? [$check] : $config->checksRun;

        // **Poświadczenie tylko wtedy, gdy kontrola naprawdę idzie do serwisu.** To nie ozdoba:
        // `--check=hardcoded-strings` czyta kod, więc musi działać na maszynie bez logowania
        // i w CI bez sekretu. Pierwsza wersja tego kodu rozwiązywała poświadczenie zawsze, więc
        // kontrola, która niczego od serwisu nie chce, kończyła się „Brak poświadczenia” (kod 2).
        $client = null;
        if ($this->needsServer($checks)) {
            $credentials = $this->session->credentials(
                tokenFlag: $this->stringOption($input, 'token'),
                apiUrlFlag: $this->stringOption($input, 'api-url'),
                profile: $this->stringOption($input, 'profile'),
            );

            if ($credentials === null) {
                $printer->error($this->session->missingCredentialsHint());

                return ExitCode::Usage->value;
            }

            $client = $this->session->client($credentials, Application::timeoutSeconds($input));
        }

        $directory = $input->getOption('directory');
        $root = is_string($directory) && $directory !== '' ? $directory : (string) getcwd();

        return (new CliValidate($printer, $this->scanner))->run(
            checks: $checks,
            config: $config,
            root: $root,
            locale: $this->stringOption($input, 'locale'),
            threshold: $failOn === 'warning' ? CheckSeverity::Warning : CheckSeverity::Error,
            client: $client,
        )->value;
    }

    /** @param list<CheckName> $checks */
    private function needsServer(array $checks): bool
    {
        foreach ($checks as $check) {
            if ($check->needsServer()) {
                return true;
            }
        }

        return false;
    }

    private function loadConfig(InputInterface $input, Printer $printer): ?ProjectConfig
    {
        $directory = $input->getOption('directory');
        $store = new ProjectConfigStore(
            is_string($directory) && $directory !== '' ? $directory : (string) getcwd()
        );

        if ($store->locate() === null) {
            $printer->error(sprintf('Nie znalazłem %s. Uruchom `babelhub init`.', ProjectConfig::FILE_NAME));

            return null;
        }

        $config = $store->load();
        if ($config === null) {
            $printer->error('Nie mogę wczytać konfiguracji projektu.');
        }

        return $config;
    }

    private function stringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
