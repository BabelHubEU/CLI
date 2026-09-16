<?php

declare(strict_types=1);

namespace BabelHub\Cli\Console\Command;

use BabelHub\Cli\CliIoException;
use BabelHub\Cli\Config\ProjectConfig;
use BabelHub\Cli\Config\ProjectConfigStore;
use BabelHub\Cli\Console\Application;
use BabelHub\Cli\ExitCode;
use BabelHub\Cli\Scan\PhpTwigScanner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `babelhub scan` — co kod mówi o tłumaczeniach, bez zaglądania do panelu.
 *
 * Polecenie **czyta wyłącznie lokalne pliki**: nie pyta serwisu, nie potrzebuje poświadczenia
 * i nie zmienia niczego. To jest jego wartość w CI i w pracy lokalnej — odpowiedź na „czy mój
 * nowy klucz jest widziany” jest natychmiastowa i nie zależy od sieci.
 *
 * `--show-paths` dokłada miejsce każdego klucza (`src/Foo.php:42`). Bez tego `scan` jest listą
 * napisów, której nie da się zweryfikować, a „skąd ten klucz?” to pierwsze pytanie przy każdym
 * nieoczekiwanym wpisie — dlatego skaner zbiera te miejsca od początku, a nie od fali, w której
 * ktoś o nie poprosi.
 */
#[AsCommand(name: 'scan', description: 'Znajduje klucze tłumaczeń w kodzie (adapter PHP/Twig)')]
final class ScanCommand extends Command
{
    public function __construct(private readonly PhpTwigScanner $scanner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('show-paths', null, InputOption::VALUE_NONE, 'Pokaż, gdzie każdy klucz występuje.')
            ->addOption('directory', null, InputOption::VALUE_REQUIRED, 'Katalog projektu (domyślnie: szukaj .babelhub.json w górę).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $printer = Application::printer($input, $output);
        $store = $this->storeFor($input);
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

        try {
            $result = $this->scanner->scan($root, $config->scanPaths);
        } catch (CliIoException $e) {
            // Katalog z konfiguracji, którego nie ma, to awaria lokalna (kod `1`): `2` mówi
            // „popraw wywołanie”, a tu wywołanie jest poprawne — brakuje katalogu.
            $printer->error($e->getMessage());

            return ExitCode::Failure->value;
        } catch (\RuntimeException $e) {
            $printer->error($e->getMessage());

            return ExitCode::Usage->value;
        }

        $printer->json([
            'project' => $config->project,
            'keys' => $result->keys(),
            'count' => $result->count(),
            'files_scanned' => $result->filesScanned(),
            'occurrences' => $result->occurrences,
        ]);

        foreach ($result->keys() as $key) {
            if ($input->getOption('show-paths')) {
                $printer->plain(sprintf('%s  %s', $key, implode(' ', $result->where($key))));
                continue;
            }

            $printer->plain($key);
        }

        $printer->result(sprintf(
            'Znaleziono %d kluczy w %d plikach (katalogi: %s).',
            $result->count(),
            $result->filesScanned(),
            implode(', ', $config->scanPaths),
        ));

        if ($result->count() === 0) {
            // Zero kluczy to nie sukces, tylko brak odpowiedzi na pytanie, po które się przyszło…
            // ale i nie błąd „coś się zepsuło”: projekt może nie mieć jeszcze ani jednego klucza.
            // Mówimy to wprost i kończymy zerem, bo to odczyt, nie bramka (bramką jest `validate`).
            $printer->warn('Skan nie znalazł żadnego klucza — sprawdź "scan.paths" w .babelhub.json.');
        }

        return ExitCode::Success->value;
    }

    private function storeFor(InputInterface $input): ProjectConfigStore
    {
        $directory = $input->getOption('directory');
        $start = is_string($directory) && $directory !== '' ? $directory : (string) getcwd();

        return new ProjectConfigStore($start);
    }
}
