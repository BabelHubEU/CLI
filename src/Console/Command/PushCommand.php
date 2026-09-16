<?php

declare(strict_types=1);

namespace BabelHub\Cli\Console\Command;

use BabelHub\Cli\Application\CliPush;
use BabelHub\Cli\CliIoException;
use BabelHub\Cli\Config\ProjectConfig;
use BabelHub\Cli\Config\ProjectConfigStore;
use BabelHub\Cli\Console\ApiSession;
use BabelHub\Cli\Console\Application;
use BabelHub\Cli\ExitCode;
use BabelHub\Cli\Git\DisabledSyncMarker;
use BabelHub\Cli\Git\GitSyncMarker;
use BabelHub\Cli\Http\ApiException;
use BabelHub\Cli\Scan\PhpTwigScanner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `babelhub push` — klucz dodany w kodzie trafia do serwisu bez ręcznej pracy.
 *
 * To domknięcie pętli z §29 planu: developer nie otwiera panelu, nie klika „nowy klucz”
 * i nie utrzymuje listy w dwóch miejscach. Cały stan kodu jedzie **jednym żądaniem**
 * (`POST /projects/{project}/sync`), co usuwa stan pośredni, w którym limiter przerywa
 * migrację w połowie.
 *
 * `--dry-run` pokazuje, co poleciałoby do serwisu, i **nie wysyła niczego** — to odpowiedź
 * na „czy skan na pewno widzi to, co myślę”, bez ryzyka zmiany danych w cudzym projekcie.
 * `--keep-missing` wyłącza oznaczanie nieobecnych kluczy (przydatne przy pushu jednego modułu,
 * gdzie brak klucza w partii nie znaczy, że zniknął z kodu).
 */
#[AsCommand(name: 'push', description: 'Wysyła klucze i teksty źródłowe z kodu do BabelHub (jedno żądanie)')]
final class PushCommand extends Command
{
    public function __construct(
        private readonly ApiSession $session,
        private readonly PhpTwigScanner $scanner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Pokaż, co zostałoby wysłane — bez zmiany danych.')
            ->addOption('keep-missing', null, InputOption::VALUE_NONE, 'Nie oznaczaj kluczy nieobecnych w tej partii jako usuniętych.')
            ->addOption(
                'unapprove-changed',
                null,
                InputOption::VALUE_NONE,
                'Cofnij zatwierdzenia w językach docelowych, gdy tekst źródłowy się zmienił (domyślnie: zostają).',
            )
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Wyślij nawet wtedy, gdy znacznik git mówi, że ten commit już poszedł.',
            )
            ->addOption(
                'no-git-tag',
                null,
                InputOption::VALUE_NONE,
                'Nie czytaj i nie przesuwaj znacznika synchronizacji w git-cie.',
            )
            ->addOption(
                'push-tag',
                null,
                InputOption::VALUE_NONE,
                'Wyślij znacznik synchronizacji do zdalnego repozytorium (dla CI w świeżym klonie).',
            )
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

        $noGitTag = (bool) $input->getOption('no-git-tag');
        $pushTag = (bool) $input->getOption('push-tag');
        if ($noGitTag && $pushTag) {
            // Sprzeczność, nie „jedna wygrywa”: `--push-tag` bez znacznika nie ma czego wysłać,
            // a milczące zignorowanie jednej z flag to dokładnie ten rodzaj cichej decyzji,
            // którego zabrania kontrakt CLI (błąd użycia = kod `2`).
            $printer->error('--no-git-tag i --push-tag wykluczają się: pierwszy wyłącza znacznik, drugi go wysyła.');

            return ExitCode::Usage->value;
        }

        $push = new CliPush(
            client: $this->session->client($credentials, Application::timeoutSeconds($input)),
            printer: $printer,
            scanner: $this->scanner,
            // `--no-git-tag` wyłącza **cały** mechanizm znacznika (także pomijanie pushu):
            // „nie dotykaj git-a” nie może znaczyć „czytaj go po cichu”.
            marker: $noGitTag ? new DisabledSyncMarker() : new GitSyncMarker(),
        );

        try {
            return $push->run(
                config: $config,
                root: $root,
                dryRun: (bool) $input->getOption('dry-run'),
                // Flaga wygrywa z plikiem: `.babelhub.yml` mówi, jak zespół pracuje na co dzień,
                // a `--keep-missing` jest decyzją na jedno uruchomienie.
                markMissingAsRemoved: $input->getOption('keep-missing')
                    ? false
                    : $config->uploadMarkMissingAsRemoved,
                // Flaga jest **dodatnia**, bo domyślnie zatwierdzenia zostają: `--unapprove-changed`
                // jest decyzją na jedno uruchomienie, a `upload.unapprove_changed` — decyzją zespołu.
                unapproveChanged: (bool) $input->getOption('unapprove-changed') || $config->uploadUnapproveChanged,
                force: (bool) $input->getOption('force'),
                publishTag: $pushTag,
            )->value;
        } catch (ApiException $e) {
            $printer->error($e->getMessage());

            return $e->exitCode()->value;
        } catch (CliIoException $e) {
            // Awaria lokalna (odczyt pliku źródłowego, dysk) to kod `1`, nie `2`.
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
