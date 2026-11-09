<?php

declare(strict_types=1);

namespace BabelHub\Cli\Console\Command;

use BabelHub\Cli\Application\CliContentPush;
use BabelHub\Cli\Config\ProjectConfig;
use BabelHub\Cli\Config\ProjectConfigStore;
use BabelHub\Cli\Content\ContentDirectory;
use BabelHub\Cli\Console\ApiSession;
use BabelHub\Cli\Console\Application;
use BabelHub\Cli\ExitCode;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `babelhub content:push` — e-maile, push, SMS i marketing z repozytorium do BabelHub.
 *
 * Osobna komenda od `push`, bo treści to inny cykl życia: nie wynikają z kodu (skan ich nie
 * znajdzie), mają typ i zmienne, a tłumacze pracują nad nimi inaczej niż nad kluczami.
 * Wspólny jest tylko kierunek — repozytorium jest źródłem prawdy.
 */
#[AsCommand(name: 'content:push', description: 'Wysyła treści z katalogu content/ do BabelHub')]
final class ContentPushCommand extends Command
{
    public function __construct(private readonly ApiSession $session)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Pokaż, co zostałoby wysłane — bez zmiany danych.')
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Katalog treści (domyślnie content).')
            ->addOption('directory', null, InputOption::VALUE_REQUIRED, 'Katalog projektu (domyślnie: szukaj .babelhub.json w górę).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $printer = Application::printer($input, $output);
        $directoryOption = $input->getOption('directory');
        $store = new ProjectConfigStore(is_string($directoryOption) && $directoryOption !== '' ? $directoryOption : (string) getcwd());

        $root = $store->locate();
        $config = $root !== null ? $store->load() : null;
        if ($root === null || $config === null) {
            $printer->error(sprintf('Nie znalazłem %s w tym katalogu ani wyżej. Uruchom `babelhub init`.', ProjectConfig::FILE_NAME));

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

        $dir = $this->stringOption($input, 'dir') ?? 'content';
        $content = new ContentDirectory(rtrim($root, '/') . '/' . ltrim($dir, '/'));

        $push = new CliContentPush($this->session->client($credentials, Application::timeoutSeconds($input)), $printer);

        return $push->run($config->project, $content, (bool) $input->getOption('dry-run'));
    }

    private function stringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
