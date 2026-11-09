<?php

declare(strict_types=1);

namespace BabelHub\Cli\Console\Command;

use BabelHub\Cli\Application\CliContentPull;
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

/** `babelhub content:pull` — treści z BabelHub do katalogu `content/`. */
#[AsCommand(name: 'content:pull', description: 'Pobiera treści z BabelHub do katalogu content/')]
final class ContentPullCommand extends Command
{
    public function __construct(private readonly ApiSession $session)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Pokaż, co zostałoby zapisane — bez zmiany plików.')
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

        $pull = new CliContentPull($this->session->client($credentials, Application::timeoutSeconds($input)), $printer);

        return $pull->run($config->project, $content, (bool) $input->getOption('dry-run'));
    }

    private function stringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
