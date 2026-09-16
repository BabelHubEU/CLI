<?php

declare(strict_types=1);

namespace BabelHub\Cli\Console\Command;

use BabelHub\Cli\Application\CliPull;
use BabelHub\Cli\CliIoException;
use BabelHub\Cli\Config\ProjectConfig;
use BabelHub\Cli\Config\ProjectConfigStore;
use BabelHub\Cli\Console\ApiSession;
use BabelHub\Cli\Console\Application;
use BabelHub\Cli\ExitCode;
use BabelHub\Cli\Http\ApiException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `babelhub pull` — katalogi z serwisu do plików w repozytorium.
 *
 * Domyślnie **pisze pliki** (atomowo) i odnotowuje wersje w `.babelhub.lock.json`.
 * `--check` nic nie zapisuje: tylko sprawdza, czy to, co jest w repozytorium, odpowiada temu,
 * co jest w serwisie, i kończy się kodem `6`, gdy serwis ma nowszą treść. To jest tryb dla CI —
 * „czy ktoś zapomniał zrobić pull”, bez ryzyka, że pipeline sam coś dopisze do repozytorium.
 *
 * Wymaga tylko zakresu `translations:read`, więc token w CI może być **tylko do czytania**.
 */
#[AsCommand(name: 'pull', description: 'Pobiera zatwierdzone katalogi z BabelHub do plików językowych (albo sprawdza zgodność z --check)')]
final class PullCommand extends Command
{
    public function __construct(private readonly ApiSession $session)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('check', null, InputOption::VALUE_NONE, 'Tylko sprawdź zgodność z serwisem; nic nie zapisuj (kod 6 przy rozjeździe).')
            ->addOption('locale', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, sprintf(
                'Pobierz tylko wskazane języki (domyślnie: %s, a bez tego wszystkie projektu).',
                'download.locales',
            ))
            ->addOption('directory', null, InputOption::VALUE_REQUIRED, 'Katalog projektu (domyślnie: szukaj .babelhub.json w górę).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $printer = Application::printer($input, $output);
        $directoryOption = $input->getOption('directory');
        $store = new ProjectConfigStore(
            is_string($directoryOption) && $directoryOption !== '' ? $directoryOption : (string) getcwd()
        );

        $root = $store->locate();
        if ($root === null) {
            $printer->error(sprintf(
                'Nie znalazłem %s w tym katalogu ani wyżej. Uruchom `babelhub init`.',
                ProjectConfig::FILE_NAME,
            ));

            return ExitCode::Usage->value;
        }

        $config = $store->load();
        if ($config === null) {
            $printer->error('Nie mogę wczytać konfiguracji projektu.');

            return ExitCode::Usage->value;
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

        try {
            // Kolejność źródeł: flaga `--locale` → `download.locales` z konfiguracji → wszystkie
            // języki projektu. Flaga jest **powtarzalna** i wygrywa w całości (a nie „dokłada się”),
            // bo mieszanie dwóch list daje zbiór, którego nikt nie umie przewidzieć z pliku.
            $requested = $input->getOption('locale');
            $locales = [];
            if (is_array($requested)) {
                foreach ($requested as $locale) {
                    if (is_string($locale) && trim($locale) !== '') {
                        $locales[] = trim($locale);
                    }
                }
            }

            return (new CliPull($this->session->client($credentials, Application::timeoutSeconds($input)), $printer))
                ->run(
                    config: $config,
                    root: $root,
                    checkOnly: (bool) $input->getOption('check'),
                    onlyLocales: $locales === [] ? $config->downloadLocales : $locales,
                )
                ->value;
        } catch (ApiException $e) {
            $printer->error($e->getMessage());

            return $e->exitCode()->value;
        } catch (CliIoException $e) {
            // Awaria lokalna (dysk, uprawnienia) to kod `1`, nie `2`: `2` znaczy „popraw
            // wywołanie”, a pipeline nie ma tu czego poprawiać.
            $printer->error($e->getMessage());

            return ExitCode::Failure->value;
        } catch (\RuntimeException $e) {
            $printer->error($e->getMessage());

            return ExitCode::Usage->value;
        }
    }

    private function stringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
