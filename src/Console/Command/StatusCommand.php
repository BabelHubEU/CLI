<?php

declare(strict_types=1);

namespace BabelHub\Cli\Console\Command;

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
 * `babelhub status` — wydruk z §10 planu: pokrycie języków, klucze, QA, ostrzeżenia.
 *
 * To **pierwszy ekran, który sprzedaje wartość, zanim ktokolwiek otworzy panel**: developer
 * wpisuje jedno polecenie i widzi, że polski jest w 100%, a niemiecki w 76% i nie jest gotowy.
 * Dlatego wydruk jest czytelny dla człowieka w trybie domyślnym, a `--output=json` daje dokładnie
 * tę samą treść maszynowo — bez parsowania tekstu przez skrypty.
 *
 * Polecenie **nie zmienia niczego** i kończy się zerem także wtedy, gdy projekt nie jest gotowy:
 * status to odczyt, a bramką jest `validate`. Mieszanie tych dwóch rzeczy kończy się tym, że
 * nikt nie uruchamia statusu w CI, bo „psuje build”.
 */
#[AsCommand(name: 'status', description: 'Pokazuje pokrycie języków, stan kluczy, kontrolę jakości i gotowość projektu')]
final class StatusCommand extends Command
{
    private const RULE = '────────────────────────────';

    public function __construct(private readonly ApiSession $session)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('locale', null, InputOption::VALUE_REQUIRED, 'Zawęź kontrolę jakości do jednego języka (pokrycie liczone jest zawsze dla wszystkich).')
            ->addOption('directory', null, InputOption::VALUE_REQUIRED, 'Katalog projektu (domyślnie: szukaj .babelhub.json w górę).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $printer = Application::printer($input, $output);

        $config = $this->loadConfig($input, $printer);
        if ($config === null) {
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

        $path = '/api/v1/projects/' . rawurlencode($config->project) . '/status';
        $locale = $this->stringOption($input, 'locale');
        if ($locale !== null) {
            $path .= '?locale=' . rawurlencode($locale);
        }

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
        $this->printForHumans($printer, $response->json());

        return ExitCode::Success->value;
    }

    /** @param array<string, mixed> $status */
    private function printForHumans(\BabelHub\Cli\Output\Printer $printer, array $status): void
    {
        $locales = is_array($status['locales'] ?? null) ? $status['locales'] : [];
        $keys = is_array($status['keys'] ?? null) ? $status['keys'] : [];
        $qa = is_array($status['qa'] ?? null) ? $status['qa'] : [];
        $summary = is_array($qa['summary'] ?? null) ? $qa['summary'] : [];

        $printer->result('');
        $printer->result(sprintf('<info>%s</info>', (string) ($status['name'] ?? '—')));
        $printer->result(self::RULE);
        $printer->result('');
        $printer->result('Locales');
        $printer->result('');

        foreach ($locales as $code => $locale) {
            if (!is_array($locale)) {
                continue;
            }

            // `plain`, a nie `result`: te wiersze **są** wynikiem polecenia, więc tryb `plain`
            // ma je pokazać (`babelhub status --output=plain | grep NOT`), a ozdobniki (nagłówki,
            // linie) zostają w trybie dla człowieka.
            $printer->plain(sprintf(
                '%-6s %3d%%   %s',
                (string) $code,
                (int) ($locale['coverage'] ?? 0),
                ($locale['ready'] ?? false) ? 'ready' : 'NOT READY',
            ));
        }

        $translated = 0;
        $missing = 0;
        foreach ($locales as $locale) {
            if (!is_array($locale)) {
                continue;
            }

            $translated += (int) ($locale['approved'] ?? 0);
            $missing += (int) ($locale['missing'] ?? 0);
        }

        $printer->result('');
        $printer->result('Keys');
        $printer->result(self::RULE);
        $printer->result('');
        $printer->plain(sprintf('Total:        %s', number_format((int) ($keys['total'] ?? 0))));
        // Jednostka jest w nawiasie, bo bez niej te dwie liczby wyglądają na sprzeczne:
        // `Total` liczy **klucze**, a `Translated` **tłumaczenia** (klucz × język). Na żywym
        // przebiegu dało to „Total: 91 / Translated: 182”, co bez wyjaśnienia czyta się jak błąd.
        $printer->plain(sprintf('Translated:   %s  (klucz × język)', number_format($translated)));
        $printer->plain(sprintf('Missing:      %s  (klucz × język)', number_format($missing)));
        $printer->plain(sprintf('Review:       %s', number_format($this->reviewCount($locales))));
        if (((int) ($keys['removed'] ?? 0)) > 0) {
            // Klucze zniknięte z kodu zostają w katalogu (D11) i liczą się do pokrycia — dlatego
            // mówimy o nich wprost, żeby „Missing” nie wyglądało na czyjś błąd tłumaczenia.
            $printer->plain(sprintf('Removed:      %s (wciąż w katalogu — usuń w panelu albo przetłumacz)', number_format((int) $keys['removed'])));
        }

        $printer->result('');
        $printer->plain(sprintf('QA errors:        %d', (int) ($summary['errors'] ?? 0)));
        if (((int) ($summary['warnings'] ?? 0)) > 0) {
            $printer->plain(sprintf('QA warnings:      %d', (int) $summary['warnings']));
        }

        if (is_string($status['last_sync_at'] ?? null)) {
            $printer->result('');
            $printer->plain(sprintf('Last sync:        %s', (string) $status['last_sync_at']));
        }

        foreach ((array) ($status['not_ready'] ?? []) as $code) {
            $printer->warn(sprintf('%s is not ready.', (string) $code));
        }
    }

    /** @param array<string, mixed> $locales */
    private function reviewCount(array $locales): int
    {
        $review = 0;
        foreach ($locales as $locale) {
            if (!is_array($locale) || !is_array($locale['counts'] ?? null)) {
                continue;
            }

            $review += (int) ($locale['counts']['REVIEW'] ?? 0);
        }

        return $review;
    }

    private function loadConfig(InputInterface $input, \BabelHub\Cli\Output\Printer $printer): ?ProjectConfig
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
