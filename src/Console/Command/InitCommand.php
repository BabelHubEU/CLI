<?php

declare(strict_types=1);

namespace BabelHub\Cli\Console\Command;

use BabelHub\Cli\CliIoException;
use BabelHub\Cli\Config\Credentials;
use BabelHub\Cli\Config\ProjectConfig;
use BabelHub\Cli\Config\ProjectConfigStore;
use BabelHub\Cli\Console\ApiSession;
use BabelHub\Cli\Console\Application;
use BabelHub\Cli\ExitCode;
use BabelHub\Cli\Http\ApiException;
use BabelHub\Cli\Output\Printer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;

/**
 * `babelhub init` — moment, w którym kończy się przeglądanie produktu, a zaczyna używanie.
 *
 * Polecenie zapisuje `.babelhub.json`: **co** synchronizować (projekt, język źródłowy, wzorzec
 * ścieżek, katalogi do skanu). Czego nie zapisuje i dlaczego — patrz `ProjectConfig`: ani
 * poświadczenia (D4), ani adresu serwera (ten sam kod jedzie na staging i na produkcję).
 *
 * Dwie ścieżki, jedna logika:
 *
 * * **interaktywnie** (`babelhub init` w terminalu) — lista projektów z serwisu, wybór języka
 *   źródłowego spośród języków **projektu**, podpowiedziany wzorzec ścieżki;
 * * **flagami** (`--project=… --source-locale=…`) — dla skryptów i onboardingów, gdzie nie ma
 *   człowieka przy terminalu. Bez `--project` i bez terminala polecenie **odmawia** zamiast
 *   zgadywać: konfiguracja projektu wybrana losowo jest gorsza niż jej brak, bo `push` wysłałby
 *   kod jednego projektu do drugiego.
 *
 * Projekt sprawdzamy w serwisie **przed** zapisaniem pliku. Bez tego literówka w slugu
 * (`learnub`) zapisałaby konfigurację, a pierwszy `push` powiedziałby `404` — czyli błąd
 * pojawiłby się w miejscu, w którym nikt nie pamięta już, skąd wziął się slug.
 */
#[AsCommand(name: 'init', description: 'Tworzy .babelhub.json: projekt, język źródłowy, ścieżki plików i katalogi do skanu')]
final class InitCommand extends Command
{
    private const DEFAULT_PATTERN = 'translations/messages.{lang}.php';

    /**
     * Kod wyjścia nieudanego odczytu listy projektów — pole, a nie parametr, bo PHP nie ma
     * krotek: metoda musi zwrócić „albo listę, albo powód niepowodzenia”, a przepakowywanie
     * tego w tablicę zaciemniłoby wywołanie.
     */
    private ExitCode $lastFailure = ExitCode::Failure;

    public function __construct(
        private readonly ProjectConfigStore $configs,
        private readonly ApiSession $session,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('project', null, InputOption::VALUE_REQUIRED, 'Slug projektu w BabelHub (pomijane = wybór z listy).')
            ->addOption('source-locale', null, InputOption::VALUE_REQUIRED, 'Kod języka źródłowego, np. en_GB.')
            ->addOption('pattern', null, InputOption::VALUE_REQUIRED, 'Wzorzec ścieżki plików językowych; musi zawierać {lang}.', self::DEFAULT_PATTERN)
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Format plików językowych: php albo json.', ProjectConfig::FORMAT_PHP)
            ->addOption('scan-path', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Katalogi do skanu (można powtórzyć).')
            ->addOption('config', null, InputOption::VALUE_REQUIRED, 'Format pliku konfiguracji: json (domyślnie) albo yaml.', 'json')
            ->addOption('directory', null, InputOption::VALUE_REQUIRED, 'Katalog, w którym zapisać konfigurację (domyślnie bieżący).')
            ->addOption('target-locale', null, InputOption::VALUE_REQUIRED, 'Język docelowy projektu próbnego (domyślnie pl_PL).', 'pl_PL')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Nadpisz istniejący .babelhub.json.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $printer = Application::printer($input, $output);
        $directoryOption = $input->getOption('directory');
        $directory = is_string($directoryOption) && $directoryOption !== '' ? $directoryOption : getcwd();
        if ($directory === false) {
            $printer->error('Nie mogę ustalić katalogu roboczego.');

            return ExitCode::Failure->value;
        }

        // Znaczenie `--config` sprawdzamy **przed jakimkolwiek żądaniem**: literówka w formacie
        // pliku nie ma po co kosztować wywołania API ani wpisu w limiterze (ta sama zasada,
        // co przy `--fail-on` w `validate`).
        $configFile = $this->stringOption($input, 'config');
        if ($configFile !== null && !in_array($configFile, ['json', 'yaml', 'yml'], true)) {
            $printer->error('Nieznana wartość --config. Dozwolone: json, yaml.');

            return ExitCode::Usage->value;
        }

        if ($this->configs->existsIn($directory) && !$input->getOption('force')) {
            $printer->error(sprintf(
                '%s już istnieje. Użyj --force, jeśli naprawdę chcesz go nadpisać.',
                $this->configs->pathIn($directory),
            ));

            return ExitCode::Usage->value;
        }

        $profile = $this->stringOption($input, 'profile');
        $credentials = $this->session->credentials(
            tokenFlag: $this->stringOption($input, 'token'),
            apiUrlFlag: $this->stringOption($input, 'api-url'),
            profile: $profile,
        );

        if ($credentials === null) {
            // Bez poświadczenia `init` zakłada **projekt próbny** (4.12): wartość przed rejestracją.
            // Adres jest wymagany, bo nie ma skąd go wziąć — tokenu też nie ma.
            $apiUrl = $this->stringOption($input, 'api-url');
            if ($apiUrl === null) {
                $printer->error($this->session->missingCredentialsHint());

                return ExitCode::Usage->value;
            }

            $trial = $this->startTrial($input, $output, $printer, $apiUrl);
            if ($trial === null) {
                return ExitCode::Usage->value;
            }

            // Po zapisaniu tokenu normalny przepływ działa bez zmian: jeden projekt, znany język.
            // Flagi ustawiamy **na tym samym wejściu**, żeby nie duplikować reszty `execute()`.
            $input->setOption('project', $trial['project']);
            $input->setOption('source-locale', $trial['source']);

            return $this->execute($input, $output);
        }

        try {
            $projects = $this->fetchProjects($credentials, $printer, Application::timeoutSeconds($input));
        } catch (ApiException $e) {
            $printer->error($e->getMessage());

            return $e->exitCode()->value;
        }

        if ($projects === null) {
            // Kod wyjścia i zdanie pochodzą **z odpowiedzi serwisu**, a nie z jednej wspólnej
            // gałęzi: `init` musi odróżnić limit (`429` → „poczekaj i powtórz”) od braku
            // uprawnień (`401`) i od awarii serwisu (`5xx`), a skrypt obsługujący retry stoi
            // właśnie na tym rozróżnieniu. Wcześniej **każde** niepowodzenie dawało `3`
            // i pusty `stderr`, czyli „coś nie wyszło” bez powiedzenia czego.
            return $this->lastFailure->value;
        }

        if ($projects === []) {
            $printer->error('Organizacja nie ma jeszcze żadnego projektu. Załóż go w panelu (Aplikacje → Nowa aplikacja).');

            return ExitCode::Usage->value;
        }

        $slug = $this->stringOption($input, 'project');
        if ($slug === null) {
            $slug = $this->chooseProject($input, $output, $projects, $printer);
            if ($slug === null) {
                return ExitCode::Usage->value;
            }
        }

        $project = null;
        foreach ($projects as $candidate) {
            if (($candidate['slug'] ?? null) === $slug) {
                $project = $candidate;
                break;
            }
        }

        if ($project === null) {
            $printer->error(sprintf(
                'Projekt „%s” nie istnieje w tej organizacji. Dostępne: %s.',
                $slug,
                implode(', ', array_map(static fn (array $row): string => (string) ($row['slug'] ?? '?'), $projects)),
            ));

            return ExitCode::Usage->value;
        }

        /** @var list<string> $locales */
        $locales = array_values(array_filter((array) ($project['locales'] ?? []), 'is_string'));
        $projectSource = is_string($project['source_locale'] ?? null) ? $project['source_locale'] : null;

        $sourceLocale = $this->stringOption($input, 'source-locale') ?? $projectSource;
        if ($sourceLocale === null) {
            $sourceLocale = $this->chooseLocale($input, $output, $locales, $printer);
            if ($sourceLocale === null) {
                return ExitCode::Usage->value;
            }
        }

        if ($locales !== [] && !in_array($sourceLocale, $locales, true)) {
            // Język źródłowy musi być językiem **projektu**: inaczej pierwszy push próbowałby
            // zapisać wartości w języku, którego projekt nie prowadzi, i dostałby `422`.
            $printer->error(sprintf(
                'Projekt %s nie prowadzi języka „%s”. Języki projektu: %s.',
                $slug,
                $sourceLocale,
                implode(', ', $locales),
            ));

            return ExitCode::Usage->value;
        }

        $scanPaths = [];
        foreach ((array) $input->getOption('scan-path') as $path) {
            if (is_string($path) && trim($path) !== '') {
                $scanPaths[] = trim($path);
            }
        }

        // Domyślne katalogi **dopasowujemy do projektu**: `src`/`templates`/`config` to typowy
        // układ, ale projekt bez `config/` nie może się przewrócić na pierwszym `scan` tylko
        // dlatego, że nasza domyślna lista pamięta o katalogu, którego nie ma. Ścieżki podane
        // wprost (`--scan-path`) zostają nietknięte — tam literówka **ma** być błędem, bo jest
        // decyzją człowieka, a nie naszym domysłem. Gdy nic nie pasuje, skanujemy cały projekt:
        // skaner i tak pomija `vendor`, `node_modules` i katalogi cache.
        if ($scanPaths === []) {
            $scanPaths = array_values(array_filter(
                ProjectConfig::DEFAULT_SCAN_PATHS,
                static fn (string $path): bool => is_dir(rtrim($directory, '/\\') . '/' . $path),
            ));

            if ($scanPaths === []) {
                $scanPaths = ['.'];
            }
        }

        $configFile = $this->stringOption($input, 'config');
        if ($configFile !== null && !in_array($configFile, ['json', 'yaml', 'yml'], true)) {
            $printer->error('Nieznana wartość --config. Dozwolone: json, yaml.');

            return ExitCode::Usage->value;
        }

        $fileName = in_array($configFile, ['yaml', 'yml'], true)
            ? ProjectConfig::FILE_NAME_YAML
            : ProjectConfig::FILE_NAME;

        try {
            $config = ProjectConfig::fromArray([
                'project' => $slug,
                'source_locale' => $sourceLocale,
                'files' => [
                    'pattern' => $this->stringOption($input, 'pattern') ?? self::DEFAULT_PATTERN,
                    'format' => $this->stringOption($input, 'format') ?? ProjectConfig::FORMAT_PHP,
                ],
                'scan' => ['paths' => $scanPaths],
            ]);
        } catch (CliIoException $e) {
            // Nie udało się zapisać pliku — awaria lokalna, kod `1`.
            $printer->error($e->getMessage());

            return ExitCode::Failure->value;
        } catch (\RuntimeException $e) {
            $printer->error($e->getMessage());

            return ExitCode::Usage->value;
        }

        $path = $this->configs->save($config, $directory, $fileName);

        $printer->json([
            'config_path' => $path,
            'project' => $config->project,
            'source_locale' => $config->sourceLocale,
            'file_pattern' => $config->filePattern,
            'file_format' => $config->fileFormat,
            'scan_paths' => $config->scanPaths,
        ]);

        $printer->result(sprintf('Zapisano %s', $path));
        $printer->result(sprintf('  projekt:        %s (%s)', $config->project, (string) ($project['name'] ?? '')));
        $printer->result(sprintf('  język źródłowy: %s', $config->sourceLocale));
        $printer->result(sprintf('  pliki:          %s (%s)', $config->filePattern, $config->fileFormat));
        $printer->result(sprintf('  skan:           %s', implode(', ', $config->scanPaths)));
        $this->reportClientEvents($credentials);

        $printer->info('Dalej: `babelhub scan` (co widzi kod), potem `babelhub push`.');

        return ExitCode::Success->value;
    }

    /**
     * Zgłoszenie dwóch kroków lejka, o których wie **tylko klient**: `cli_installed` i `init`.
     *
     * Serwer nie widzi ani instalacji pakietu, ani uruchomienia `init`, więc CLI mówi o nich
     * wprost — własnym tokenem, po udanym zapisie konfiguracji. Bez poświadczenia nie wysyłamy
     * nic: instalacja bez konta nie jest raportowana, bo nie ma czyja być.
     *
     * Błąd zgłoszenia **nigdy** nie zmienia kodu wyjścia: lejek jest obserwacją, a nie częścią
     * `init`. Wyjątek jest łapany szeroko, bo tu chodzi wyłącznie o „nie przeszkadzaj".
     */
    private function reportClientEvents(Credentials $credentials): void
    {
        try {
            $this->session->client($credentials)->post('/api/v1/events', [
                'names' => ['cli_installed', 'init'],
            ]);
        } catch (\Throwable) {
            // Cisza jest tu decyzją: `init` już się udał i nie może paść przez statystykę.
        }
    }

    /**
     * Start projektu próbnego: jedno żądanie, token zapisany od razu.
     *
     * Token jest zapisywany **przed** dalszymi krokami: gdyby `init` padł później (np. na zapisie
     * konfiguracji), użytkownik nie traci projektu — wystarczy powtórzyć `babelhub init`.
     *
     * @return array{project: string, source: string}|null
     */
    private function startTrial(
        InputInterface $input,
        OutputInterface $output,
        Printer $printer,
        string $apiUrl,
    ): ?array {
        $source = $this->stringOption($input, 'source-locale') ?? 'en_GB';
        $target = $this->stringOption($input, 'target-locale') ?? 'pl_PL';

        try {
            $response = $this->session->clientFor($apiUrl, null, Application::timeoutSeconds($input))
                ->post('/api/v1/trial', ['source_locale' => $source, 'targets' => [$target]]);
        } catch (ApiException $e) {
            $printer->error($e->getMessage());

            return null;
        }

        if (!$response->isSuccess()) {
            $printer->error(sprintf('Nie udało się założyć projektu próbnego: %s', $response->message()));

            return null;
        }

        $body = $response->json();
        $token = $body['token'] ?? null;
        $project = $body['project'] ?? null;
        $expiresAt = $body['expires_at'] ?? null;
        if (!is_string($token) || !is_string($project) || !is_string($expiresAt)) {
            $printer->error('Serwis odpowiedział bez tokenu projektu próbnego.');

            return null;
        }

        $this->session->save(new Credentials(
            apiUrl: $apiUrl,
            token: $token,
            scopes: ['translations:read', 'translations:write'],
        ));

        $printer->info(sprintf(
            'Projekt próbny „%s” gotowy. Wygasa %s.',
            $project,
            $expiresAt,
        ));
        $printer->info(sprintf(
            'Załóż konto i wklej ten token na %s/trial/claim — projekt stanie się Twój, a token zostanie ważny.',
            rtrim($apiUrl, '/'),
        ));

        return ['project' => $project, 'source' => $source];
    }

    /**
     * Lista projektów albo `null`, gdy odpowiedź się nie udała (kod wyjścia w `$lastFailure`).
     *
     * @return list<array<string, mixed>>|null
     */
    private function fetchProjects(Credentials $credentials, Printer $printer, float $timeoutSeconds): ?array
    {
        // Klienta budujemy z poświadczenia: `init` nie ma jeszcze konfiguracji projektu,
        // więc adres serwisu bierze się wyłącznie z poświadczenia (flaga → zmienna → plik).
        $response = $this->session->client($credentials, $timeoutSeconds)->get('/api/v1/projects');

        if (!$response->isSuccess()) {
            $printer->error($response->message());
            $this->lastFailure = $response->exitCode();

            return null;
        }

        $projects = $response->json()['projects'] ?? [];

        return is_array($projects) ? array_values(array_filter($projects, 'is_array')) : [];
    }

    /**
     * @param list<array<string, mixed>> $projects
     */
    private function chooseProject(InputInterface $input, OutputInterface $output, array $projects, Printer $printer): ?string
    {
        if (!$input->isInteractive()) {
            $printer->error(sprintf(
                'Bez terminala musisz podać projekt wprost: --project=<slug>. Dostępne: %s.',
                implode(', ', array_map(static fn (array $row): string => (string) ($row['slug'] ?? '?'), $projects)),
            ));

            return null;
        }

        $choices = [];
        foreach ($projects as $project) {
            $slug = (string) ($project['slug'] ?? '?');
            $choices[$slug] = sprintf('%s (%s)', (string) ($project['name'] ?? $slug), $slug);
        }

        $helper = new QuestionHelper();
        $answer = $helper->ask($input, $output, new ChoiceQuestion('Który projekt synchronizować?', $choices));

        return is_string($answer) ? $answer : null;
    }

    /**
     * @param list<string> $locales
     */
    private function chooseLocale(InputInterface $input, OutputInterface $output, array $locales, Printer $printer): ?string
    {
        if ($locales === []) {
            $printer->error('Projekt nie ma przypisanych języków. Dodaj je w panelu (Aplikacja → Języki projektu).');

            return null;
        }

        if (!$input->isInteractive()) {
            $printer->error(sprintf(
                'Bez terminala musisz podać język źródłowy: --source-locale=<kod>. Języki projektu: %s.',
                implode(', ', $locales),
            ));

            return null;
        }

        $helper = new QuestionHelper();
        $answer = $helper->ask($input, $output, new ChoiceQuestion('Który język jest źródłowy?', $locales));

        return is_string($answer) ? $answer : null;
    }

    private function stringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
