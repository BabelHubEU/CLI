<?php

declare(strict_types=1);

namespace BabelHub\Cli\Console\Command;

use BabelHub\Cli\Application\CliPull;
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
 * `babelhub sync` — jedno polecenie robiące obie strony pętli, w kolejności, która ma znaczenie.
 *
 * **Najpierw `push`, potem `pull`.** Odwrotna kolejność wyglądałaby na równie sensowną, a jest
 * gorsza: `pull` pobiera wyłącznie teksty **zatwierdzone**, więc nowy klucz dodany w kodzie
 * (i jeszcze nieprzetłumaczony) i tak nie wróci — a gdyby `pull` szedł pierwszy, deweloper
 * zobaczyłby w plikach stan sprzed własnej zmiany i musiałby się domyślać, czy push w ogóle
 * zadziałał. Push najpierw znaczy: „serwis wie o moim kodzie, a potem dostaję z powrotem to,
 * co jest gotowe”.
 *
 * Polecenie jest **przerywane przez pierwszy błąd**: `push`, który zwrócił `6` (serwis pominął
 * część kluczy), nie jest przepuszczany dalej, bo `pull` na niekompletnym stanie daje pliki,
 * których nikt nie zweryfikował. Kod wyjścia jest kodem kroku, który poległ.
 */
#[AsCommand(name: 'sync', description: 'Push z kodu, potem pull katalogów — cała pętla jednym poleceniem')]
final class SyncCommand extends Command
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
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Pokaż, co zostałoby wysłane — bez zmiany danych i bez zapisu plików.')
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

        $client = $this->session->client($credentials, Application::timeoutSeconds($input));
        $dryRun = (bool) $input->getOption('dry-run');
        $noGitTag = (bool) $input->getOption('no-git-tag');
        $pushTag = (bool) $input->getOption('push-tag');
        if ($noGitTag && $pushTag) {
            $printer->error('--no-git-tag i --push-tag wykluczają się: pierwszy wyłącza znacznik, drugi go wysyła.');

            return ExitCode::Usage->value;
        }

        try {
            $printer->info('— push —');
            $pushed = (new CliPush(
                $client,
                $printer,
                $this->scanner,
                $noGitTag ? new DisabledSyncMarker() : new GitSyncMarker(),
            ))->run(
                config: $config,
                root: $root,
                dryRun: $dryRun,
                markMissingAsRemoved: $input->getOption('keep-missing')
                    ? false
                    : $config->uploadMarkMissingAsRemoved,
                unapproveChanged: (bool) $input->getOption('unapprove-changed') || $config->uploadUnapproveChanged,
                force: (bool) $input->getOption('force'),
                publishTag: $pushTag,
            );

            if (!$pushed->isSuccess()) {
                return $pushed->value;
            }

            if ($dryRun) {
                $printer->info('[dry-run] Pomijam pull — nic nie zostało wysłane.');

                return ExitCode::Success->value;
            }

            $printer->info('— pull —');

            return (new CliPull($client, $printer))->run($config, $root, false, $config->downloadLocales)->value;
        } catch (ApiException $e) {
            $printer->error($e->getMessage());

            return $e->exitCode()->value;
        } catch (CliIoException $e) {
            // Awaria lokalna (dysk, uprawnienia) to kod `1`, nie `2`.
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
