<?php

declare(strict_types=1);

namespace Tests\Contract;

use BabelHub\Cli\Checks\CheckName;
use BabelHub\Cli\Console\Command\ValidateCommand;
use BabelHub\Cli\ExitCode;
use BabelHub\Cli\Output\OutputMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Tests\Cli\Support\CliWorkspace;
use Tests\Cli\Support\FakeApiClient;

/**
 * Fragmenty CI (`examples/ci/`) **przeciw prawdziwemu CLI** — kontrakt, nie dokumentacja.
 *
 * Gotowy fragment konfiguracji starzeje się szybciej niż cokolwiek innego w repozytorium:
 * nikt go nie uruchamia (bo leży w innym repozytorium), a kiedy przestaje działać, dowiaduje się
 * o tym klient — w swoim CI, bez kontekstu. Ten test czyta oba pliki i sprawdza rzeczy, które
 * da się sprawdzić bez GitHuba i GitLaba:
 *
 * 1. **YAML się parsuje** — literówka we wcięciu to fragment, który nie wstanie;
 * 2. **każde polecenie `babelhub <cmd>` istnieje** — listę komend bierzemy z żywej aplikacji
 *    (`list --format=json`), a nie z drugiej listy w kodzie testu;
 * 3. **każda flaga istnieje dla tej komendy** — z `--help` prawdziwego CLI, czyli z tego, co
 *    widzi użytkownik;
 * 4. **bramka faktycznie blokuje** — żadnego `|| true`, `continue-on-error` ani `allow_failure`
 *    przy krokach, które mają decydować o merge'u;
 * 5. **poświadczenie nie wycieka do pliku** — token wyłącznie przez `secrets` / zmienną CI;
 * 6. **obie platformy robią to samo** — ten sam zbiór poleceń w Actions i w GitLabie, więc
 *    fragmenty nie rozjadą się po cichu, gdy ktoś poprawi tylko jeden z nich.
 */
final class CiFragmentTest extends TestCase
{
    private const DIRECTORY = 'examples/ci';

    /** @var array<string, string> nazwa pliku => treść */
    private static array $sources = [];

    /** @var array<string, list<string>> komenda => opcje z `--help` (dwa fragmenty pytają o to samo) */
    private static array $options = [];

    private CliWorkspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = new CliWorkspace();
    }

    protected function tearDown(): void
    {
        $this->workspace->cleanup();

        parent::tearDown();
    }

    /** @return iterable<string, array{0: string}> */
    public static function fragments(): iterable
    {
        yield 'GitHub Actions' => ['github-actions.yml'];
        yield 'GitLab CI' => ['gitlab-ci.yml'];
    }

    #[DataProvider('fragments')]
    public function testTheFragmentParsesAsYamlWithJobs(string $file): void
    {
        $parsed = Yaml::parse(self::source($file));

        self::assertIsArray($parsed, $file . ' musi być mapą YAML');
        self::assertNotSame([], $parsed, $file . ' nie może być pusty');
    }

    #[DataProvider('fragments')]
    public function testEveryCommandUsedByTheFragmentExistsInTheCli(string $file): void
    {
        $known = $this->cliCommandNames();

        foreach ($this->invocations(self::source($file)) as [$command, $arguments]) {
            self::assertContains(
                $command,
                $known,
                sprintf('%s woła `babelhub %s`, którego nie ma w CLI', $file, $command),
            );
            self::assertNotSame([], $arguments, $file . ': każde wywołanie ma być komendą z argumentami');
        }
    }

    #[DataProvider('fragments')]
    public function testEveryFlagUsedByTheFragmentExistsForThatCommand(string $file): void
    {
        /** @var array<string, list<string>> $helpOptions cache: komenda => opcje z --help */
        $helpOptions = [];
        $problems = [];

        foreach ($this->invocations(self::source($file)) as [$command, $arguments]) {
            if (!isset($helpOptions[$command])) {
                $helpOptions[$command] = $this->optionsOf($command);
            }

            foreach ($arguments as $argument) {
                if (!str_starts_with($argument, '--')) {
                    continue;
                }

                $flag = '--' . explode('=', ltrim($argument, '-'), 2)[0];
                if (!in_array($flag, $helpOptions[$command], true)) {
                    $problems[] = sprintf('babelhub %s %s', $command, $flag);
                }
            }
        }

        self::assertSame([], $problems, sprintf(
            '%s używa flag, których CLI nie zna — fragment przewróci się w CI klienta: %s',
            $file,
            implode(', ', $problems),
        ));
    }

    /**
     * Wartości opcji, które są **listami zamkniętymi**, sprawdzamy osobno — bo `--fail-on=eror`
     * ma poprawną nazwę flagi i przechodzi przez sprawdzenie wyżej. Listy nie duplikujemy
     * w teście: `--output` bierze się z `OutputMode`, `--fail-on` ze stałej komendy, a `--check`
     * z `CheckName` (jednego źródła nazw kontroli), więc zmiana w CLI przenosi się tu sama.
     * Inne wartości (adresy, ścieżki, wzorce) są otwarte i zostają poza testem — ich walidacja
     * należy do CLI w czasie wykonania.
     */
    #[DataProvider('fragments')]
    public function testEveryClosedListValueUsedByTheFragmentIsValid(string $file): void
    {
        $closed = [
            'output' => OutputMode::values(),
            'fail-on' => ValidateCommand::THRESHOLDS,
            'check' => CheckName::names(),
        ];

        $problems = [];
        foreach ($this->invocations(self::source($file)) as [$command, $arguments]) {
            foreach ($arguments as $argument) {
                if (!str_contains($argument, '=')) {
                    continue;
                }

                [$name, $value] = explode('=', ltrim($argument, '-'), 2);
                if (!isset($closed[$name])) {
                    continue;
                }

                if (!in_array($value, $closed[$name], true)) {
                    $problems[] = sprintf(
                        'babelhub %s --%s=%s (dozwolone: %s)',
                        $command,
                        $name,
                        $value,
                        implode(', ', $closed[$name]),
                    );
                }
            }
        }

        self::assertSame([], $problems, sprintf(
            '%s podaje wartość spoza zamkniętej listy — CLI odrzuci to kodem 2 (błąd użycia): %s',
            $file,
            implode(', ', $problems),
        ));
    }

    /**
     * Cztery **nazwane kontrole** muszą być w obu fragmentach wołane **po nazwie** i tak samo
     * nazwane w kroku/jobie (1.14).
     *
     * To jest kryterium z planu, nie kosmetyka: `babelhub validate` bez `--check` uruchamia
     * zestaw z `checks.run`, a wtedy log (i lista checków w pull requeście) mówi tylko
     * „validate nie przeszło”. Nazwana kontrola mówi wprost, co blokuje merge — i tylko dlatego
     * cztery kroki po jednej kontroli mają sens. Nazwy bierzemy z `CheckName`, więc zmiana nazwy
     * w CLI zapala ten test, a nie psuje pipeline'u klienta.
     *
     * Kształt nazwy jest różny na obu platformach (`- name: …` w Actions, `"babelhub:coverage":`
     * w GitLabie), więc sprawdzamy **jedno i drugie**, zamiast dopasowywać się do jednej:
     * nazwa kontroli ma stać w miejscu, w którym platforma pokazuje ją człowiekowi.
     */
    #[DataProvider('fragments')]
    public function testEveryNamedCheckIsBothInvokedAndVisiblyNamed(string $file): void
    {
        $configuration = self::withoutComments(self::source($file));
        $problems = [];

        foreach (CheckName::names() as $name) {
            if (!str_contains($configuration, '--check=' . $name)) {
                $problems[] = sprintf('%s: brak wywołania `--check=%s`', $file, $name);
            }

            $named = sprintf(
                '/(^\s*-\s*name:.*%1$s)|(^\s*"?[\w.:-]*%1$s[\w.:-]*"?:\s*$)/m',
                preg_quote($name, '/'),
            );
            if (preg_match($named, $configuration) !== 1) {
                $problems[] = sprintf('%s: brak kroku/joba nazwanego „%s”', $file, $name);
            }
        }

        self::assertSame([], $problems, sprintf(
            '%s: nazwana kontrola ma być widoczna bez czytania logu — nazwa kroku i flaga `--check`',
            $file,
        ));
    }

    #[DataProvider('fragments')]
    public function testTheGateIsNotSilenced(string $file): void
    {
        $source = self::source($file);
        // **Komentarze to proza, nie konfiguracja.** Ten plik sam tłumaczy, dlaczego nie ma
        // `|| true` — a pierwsza wersja tego testu zapalała się właśnie na tym zdaniu, czyli
        // mierzyła swoją własną dokumentację. Sprawdzamy więc to, co platforma wykonuje.
        $configuration = self::withoutComments($source);

        // Trzy sposoby na bramkę, która nie blokuje. Każdy z nich zamienia „kontrolę” w „raport”,
        // a to jest dokładnie ten rodzaj cichej porażki, przed którym broni cały kontrakt kodów
        // wyjścia (1.16).
        self::assertStringNotContainsString('|| true', $configuration, $file . ': `|| true` kasuje kod wyjścia bramki');
        self::assertStringNotContainsString('continue-on-error', $configuration, $file . ': krok bramki nie może mieć `continue-on-error`');
        self::assertStringNotContainsString('allow_failure', $configuration, $file . ': job bramki nie może mieć `allow_failure`');

        // Bramka musi być wprost widoczna jako krok — fragment bez `validate` niczego nie pilnuje.
        self::assertStringContainsString('babelhub validate', $configuration, $file . ': brak `babelhub validate`');
        self::assertStringContainsString(
            '--fail-on=error',
            $configuration,
            $file . ': próg bramki ma być **jawny** — domyślny próg zmieni się razem z domyślną wagą',
        );
    }

    #[DataProvider('fragments')]
    public function testTheTokenComesFromTheCiSecretStoreAndNeverFromTheFile(string $file): void
    {
        $source = self::source($file);
        $configuration = self::withoutComments($source);

        self::assertStringContainsString(
            'BABELHUB_TOKEN',
            $configuration,
            $file . ': poświadczenie musi mieć jedną, wspólną nazwę — tę samą, którą czyta CLI',
        );

        // Wzorzec tokenu osobistego (`pat_<12 hex>.<sekret>`) albo klucza aplikacji (`<id>.<sekret>`)
        // w pliku konfiguracyjnym znaczy jedno: sekret trafił do repozytorium. Ten wzorzec
        // sprawdzamy w **całym** pliku, także w komentarzach — tam sekret wygląda tak samo źle.
        self::assertDoesNotMatchRegularExpression(
            '/pat_[0-9a-f]{8,}\.[A-Za-z0-9_\-]{8,}/',
            $source,
            $file . ': w pliku jest **wartość** tokenu, a nie odwołanie do sekretu',
        );

        // `.babelhub.json` nie zawiera poświadczenia nigdy (niezmiennik projektu) — a gdyby ktoś
        // próbował je tam wstawić przez CI, ten warunek to złapie.
        self::assertStringNotContainsString(
            '.babelhub.json',
            $configuration,
            $file . ': token nie może iść do pliku w repozytorium',
        );
    }

    public function testBothPlatformsRunTheSameBabelhubCommands(): void
    {
        $github = $this->commandSet(self::source('github-actions.yml'));
        $gitlab = $this->commandSet(self::source('gitlab-ci.yml'));

        self::assertSame(
            $github,
            $gitlab,
            'Fragmenty dla GitHuba i GitLaba muszą robić to samo — inaczej jeden klient dostaje ' .
            'bramkę jakości, a drugi nie (i nikt tego nie zauważy, bo pliki leżą w różnych miejscach)',
        );
    }

    public function testBothPlatformsCommitTheTranslationsBackOnTheMainBranch(): void
    {
        foreach (['github-actions.yml', 'gitlab-ci.yml'] as $file) {
            $source = self::source($file);

            self::assertStringContainsString('babelhub sync', $source, $file . ': brak połowy „pull” pętli');
            self::assertStringContainsString('translations/', $source, $file . ': commit zwrotny musi objąć pliki tłumaczeń');
            self::assertStringContainsString(
                '.babelhub.lock.json',
                $source,
                $file . ': lock musi jechać z plikami, inaczej następny `pull` pobiera wszystko od nowa',
            );
        }
    }

    public function testTheDocumentationPointsAtEveryFragment(): void
    {
        $documentation = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/ci.md');

        foreach (array_keys(iterator_to_array(self::fragments())) as $label) {
            self::assertStringContainsString($label, $documentation, 'docs/ci.md ma nazywać oba fragmenty');
        }

        foreach (['github-actions.yml', 'gitlab-ci.yml'] as $file) {
            self::assertStringContainsString(
                $file,
                $documentation,
                sprintf('docs/ci.md nie wspomina o %s — fragment bez dokumentacji nie zostanie znaleziony', $file),
            );
        }
    }

    private static function source(string $file): string
    {
        if (!isset(self::$sources[$file])) {
            $path = dirname(__DIR__, 2) . '/' . self::DIRECTORY . '/' . $file;
            self::assertFileExists($path);

            self::$sources[$file] = (string) file_get_contents($path);
        }

        return self::$sources[$file];
    }

    /**
     * Treść bez komentarzy — czyli to, co platforma faktycznie wykona.
     *
     * Potrzebne, bo fragmenty **tłumaczą swoje decyzje w komentarzach**: „nigdzie nie ma `|| true`”
     * to zdanie, które pierwsza wersja tego testu brała za użycie `|| true`. Reguła dotyczy
     * konfiguracji, nie prozy — a odróżnienie ich jest tańsze niż pisanie fragmentów bez wyjaśnień.
     */
    private static function withoutComments(string $source): string
    {
        $lines = [];
        foreach (explode("\n", $source) as $line) {
            $trimmed = ltrim($line);
            if (str_starts_with($trimmed, '#')) {
                continue;
            }

            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * Wywołania CLI w treści fragmentu — `babelhub <komenda> [flagi…]`, linia po linii.
     *
     * Wyrażenie wymaga, by przed `babelhub` nie było znaku słowa ani kropki: bez tego
     * `composer global config repositories.babelhub vcs …` wyglądało jak wywołanie komendy `vcs`,
     * a test pytał CLI o pomoc dla polecenia, którego nie ma (i przewracał się zamiast sprawdzać).
     *
     * @return list<array{0: string, 1: list<string>}>
     */
    private function invocations(string $source): array
    {
        $found = [];
        foreach (explode("\n", $source) as $line) {
            if (str_starts_with(ltrim($line), '#')) {
                continue;
            }

            preg_match_all(
                '/(?<![\w.])babelhub\s+([a-z][a-z-]*)((?:\s+--[a-z-]+(?:=[^\s]+)?)*)/',
                $line,
                $matches,
                PREG_SET_ORDER,
            );

            foreach ($matches as $match) {
                preg_match_all('/--[a-z-]+(?:=[^\s]+)?/', $match[2], $flags);

                /** @var list<string> $arguments */
                $arguments = $flags[0];
                $found[] = [$match[1], $arguments];
            }
        }

        self::assertNotSame([], $found, 'we fragmencie nie ma ani jednego wywołania `babelhub …`');

        return $found;
    }

    /** @return list<string> nazwy komend z żywej aplikacji */
    private function cliCommandNames(): array
    {
        // `projectScoped: false`, bo `list` nie zna `--directory` — harness dokładający tę opcję
        // do każdej komendy kończy się błędem użycia i testem, który mierzy własny artefakt.
        $result = $this->workspace->run(['command' => 'list', '--format' => 'json'], new FakeApiClient(), null, false);

        self::assertSame(ExitCode::Success->value, $result['code'], '`babelhub list` musi działać bez sieci');

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(trim($result['stdout']), true, 512, JSON_THROW_ON_ERROR);
        /** @var list<array<string, mixed>> $commands */
        $commands = is_array($decoded['commands'] ?? null) ? array_values($decoded['commands']) : [];

        $names = [];
        foreach ($commands as $row) {
            // `list --format=json` zwraca **listę obiektów** z polem `name`, nie mapę nazw —
            // `array_keys()` dawałoby tu `0,1,2…` i test „przechodziłby” na pustej liście.
            if (is_string($row['name'] ?? null)) {
                $names[] = $row['name'];
            }
        }

        self::assertContains('validate', $names, 'lista komend musi pochodzić z aplikacji, a nie z pustej odpowiedzi');

        return $names;
    }

    /** @return list<string> opcje, które `babelhub <komenda> --help` pokazuje użytkownikowi */
    private function optionsOf(string $command): array
    {
        if (isset(self::$options[$command])) {
            return self::$options[$command];
        }

        $result = $this->workspace->runArgv(['babelhub', $command, '--help'], new FakeApiClient());

        self::assertSame(ExitCode::Success->value, $result['code'], $command . ': `--help` musi działać bez konfiguracji');

        preg_match_all('/--([a-z][a-z-]*)/', $result['stdout'], $matches);

        $options = [];
        foreach ($matches[1] as $name) {
            $options[] = '--' . $name;
        }

        return self::$options[$command] = array_values(array_unique($options));
    }

    /** @return list<string> */
    private function commandSet(string $source): array
    {
        $commands = [];
        foreach ($this->invocations($source) as [$command]) {
            $commands[] = $command;
        }

        sort($commands);

        return array_values(array_unique($commands));
    }
}
