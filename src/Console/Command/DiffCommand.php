<?php

declare(strict_types=1);

namespace BabelHub\Cli\Console\Command;

use BabelHub\Cli\Application\CliDiff;
use BabelHub\Cli\Config\CatalogLock;
use BabelHub\Cli\Config\CatalogLockStore;
use BabelHub\Cli\Config\ProjectConfig;
use BabelHub\Cli\Config\ProjectConfigStore;
use BabelHub\Cli\Console\ApiSession;
use BabelHub\Cli\Console\Application;
use BabelHub\Cli\ExitCode;
use BabelHub\Cli\Http\ApiException;
use BabelHub\Cli\Output\Printer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `babelhub diff` — **co się zmieniło w kluczach od wskazanej chwili**.
 *
 * Odpowiada na dwa pytania, które dotąd wymagały zgadywania:
 *
 * * w CI: „co ten pull request wniósł do tłumaczeń?” — nowe klucze do przetłumaczenia, zmienione
 *   teksty źródłowe (tłumaczenie może być nieaktualne), klucze usunięte z kodu;
 * * przy pracy: „co mam do zrobienia od ostatniej synchronizacji?” — bez `--since` bierzemy
 *   datę z `.babelhub.lock.json`, czyli z **własnego, ostatniego `pull`**, bo tylko klient wie,
 *   kiedy ostatnio pytał.
 *
 * Świadome decyzje:
 *
 * * **`--since` bez wartości domyślnej „w serwisie”.** Gdy nie ma locka i nie ma flagi, to jest
 *   błąd użycia (`2`) z podpowiedzią, a nie „pokaż wszystko”: diff bez punktu odniesienia
 *   wygląda jak „nic się nie zmieniło”, a znaczy „nie wiem od kiedy”;
 * * **kod wyjścia `0` także wtedy, gdy coś się zmieniło.** To odczyt, nie bramka — bramką jest
 *   `validate`. Mieszanie ich kończy się tym, że nikt nie uruchamia `diff` w CI, bo „psuje build”;
 * * **`--output=plain` daje jedną linię na pozycję** (`new klucz`), więc log CI da się filtrować
 *   bez parsowania JSON-a.
 */
#[AsCommand(name: 'diff', description: 'Pokazuje nowe, zmienione i usunięte klucze od wskazanej chwili (domyślnie: od ostatniego pull)')]
final class DiffCommand extends Command
{
    public function __construct(private readonly ApiSession $session)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('since', null, InputOption::VALUE_REQUIRED, sprintf(
                'Data ISO-8601 ze strefą, np. 2026-09-14T09:00:00Z (domyślnie: czas ostatniego pull z %s).',
                CatalogLock::FILE_NAME,
            ))
            ->addOption('directory', null, InputOption::VALUE_REQUIRED, 'Katalog projektu (domyślnie: szukaj .babelhub.json w górę).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $printer = Application::printer($input, $output);

        $directory = $input->getOption('directory');
        $root = is_string($directory) && $directory !== '' ? $directory : (string) getcwd();
        $store = new ProjectConfigStore($root);

        if ($store->locate() === null) {
            $printer->error(sprintf('Nie znalazłem %s. Uruchom `babelhub init`.', ProjectConfig::FILE_NAME));

            return ExitCode::Usage->value;
        }

        $config = $store->load();
        if ($config === null) {
            $printer->error('Nie mogę wczytać konfiguracji projektu.');

            return ExitCode::Usage->value;
        }

        $since = $this->stringOption($input, 'since');
        if ($since === null) {
            $since = $this->sinceFromLock($root);
            if ($since === null) {
                $printer->error(sprintf(
                    'Nie wiem, od kiedy liczyć zmiany: nie ma %s (nigdy nie robiłeś `pull`) i nie podałeś --since. ' .
                    'Podaj datę ISO-8601, np. --since=2026-09-01T00:00:00Z.',
                    CatalogLock::FILE_NAME,
                ));

                return ExitCode::Usage->value;
            }
        }

        $credentials = $this->session->credentials(
            tokenFlag: $this->stringOption($input, 'token'),
            apiUrlFlag: $this->stringOption($input, 'api-url'),
            profile: $this->stringOption($input, 'profile'),
        );

        if ($credentials === null) {
            $printer->error($this->session->missingCredentialsHint());

            return ExitCode::Usage->value;
        }

        $path = '/api/v1/projects/' . rawurlencode($config->project) . '/keys/diff?since=' . rawurlencode($since);

        try {
            $response = $this->session->client($credentials, Application::timeoutSeconds($input))->get($path);
        } catch (ApiException $e) {
            $printer->error($e->getMessage());

            return $e->exitCode()->value;
        }

        if (!$response->isSuccess()) {
            $printer->error($response->message());

            return $response->exitCode()->value;
        }

        $printer->jsonBody($response->body);
        (new CliDiff($printer))->print($response->json(), $config->project, $since);

        return ExitCode::Success->value;
    }

    /**
     * Data ostatniego `pull` z locka — **najstarsza** z języków.
     *
     * Jedno `pull` zapisuje wszystkie języki z tą samą datą, więc w praktyce nie ma z czego
     * wybierać. Gdyby jednak ktoś pobrał języki osobnymi poleceniami, najstarsza data jest
     * bezpieczniejsza: pokaże o kilka zmian za dużo, a nie pominie tych z między pobrań.
     */
    private function sinceFromLock(string $root): ?string
    {
        $lock = (new CatalogLockStore($root))->load();
        if ($lock === null) {
            return null;
        }

        $dates = [];
        foreach ($lock->locales as $entry) {
            $pulledAt = $entry['pulled_at'] ?? '';
            if ($pulledAt !== '') {
                $dates[] = $pulledAt;
            }
        }

        if ($dates === []) {
            return null;
        }

        sort($dates);

        return $dates[0];
    }

    private function stringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
