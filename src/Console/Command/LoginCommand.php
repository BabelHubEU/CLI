<?php

declare(strict_types=1);

namespace BabelHub\Cli\Console\Command;

use BabelHub\Cli\Application\CliLogin;
use BabelHub\Cli\Application\SystemSleeper;
use BabelHub\Cli\Config\CredentialsStore;
use BabelHub\Cli\Config\PendingPairingStore;
use BabelHub\Cli\Console\Application;
use BabelHub\Cli\Console\Browser;
use BabelHub\Cli\ExitCode;
use BabelHub\Cli\Http\ApiClient;
use BabelHub\Cli\Http\ApiClientInterface;
use BabelHub\Cli\Http\ApiException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `babelhub login` — trzy drogi do jednego celu, w kolejności od najbezpieczniejszej.
 *
 * 1. **Parowanie w przeglądarce** (domyślnie): terminal pokazuje kod, człowiek zatwierdza go
 *    w panelu, poświadczenie wraca do terminala. Nic nie trzeba kopiować.
 * 2. **`--complete=<url>`**: dokończenie parowania, gdy pierwsze polecenie skończyło się bez
 *    TTY (CI, SSH) — patrz `CliLogin::completePairing`.
 * 3. **`--token=<pat>`**: dla kont serwisowych i CI, gdzie nie ma człowieka przy terminalu.
 *    To furtka, nie domyślna droga: token w linii poleceń trafia do historii powłoki.
 */
#[AsCommand(name: 'login', description: 'Loguje CLI do serwisu (parowanie w przeglądarce, --complete albo --token)')]
final class LoginCommand extends Command
{
    /** @param callable(string, ?string, float): ApiClientInterface|null $clientFactory */
    public function __construct(
        private readonly CredentialsStore $store,
        private readonly Browser $browser,
        private $clientFactory = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('complete', null, InputOption::VALUE_REQUIRED, 'Dokończ zaczęte parowanie: podaj adres z kodu (verification_uri_complete).')
            ->addOption('no-browser', null, InputOption::VALUE_NONE, 'Nie otwieraj przeglądarki — tylko wypisz kod i adres.')
            ->addOption('no-wait', null, InputOption::VALUE_NONE, 'Wypisz kod i zakończ; dokończ później przez --complete.')
            ->addOption('wait', null, InputOption::VALUE_REQUIRED, 'Ile sekund czekać na zatwierdzenie.', '300');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $printer = Application::printer($input, $output);
        $profile = $this->profile($input);
        $wait = max(1, (int) $input->getOption('wait'));

        $apiUrl = $this->apiUrl($input);
        if ($apiUrl === null) {
            $printer->error(
                'Nie wiem, z jakim serwisem się połączyć. Podaj --api-url, ustaw BABELHUB_API_URL ' .
                'albo najpierw zapisz poświadczenie.'
            );

            return ExitCode::Usage->value;
        }

        try {
            $login = new CliLogin(
                client: ($this->clientFactory ?? $this->defaultClientFactory())(
                    $apiUrl,
                    $this->token($input),
                    Application::timeoutSeconds($input),
                ),
                store: $this->store,
                pending: PendingPairingStore::alongsideCredentials($this->store->path()),
                printer: $printer,
                browser: $this->browser,
                sleeper: new SystemSleeper(),
            );

            $complete = $input->getOption('complete');
            $token = $this->token($input);

            $code = match (true) {
                // Furtka dla CI i kont serwisowych: weryfikujemy token i zapisujemy go.
                $token !== null => $login->withToken($token, $profile),
                is_string($complete) && $complete !== '' => $login->completePairing($complete, $wait, $profile),
                default => $login->startPairing(
                    openBrowser: !$input->getOption('no-browser'),
                    profile: $profile,
                    waitSeconds: $wait,
                    wait: !$input->getOption('no-wait'),
                ),
            };
        } catch (ApiException $e) {
            $printer->error($e->getMessage());

            return $e->exitCode()->value;
        } catch (\InvalidArgumentException $e) {
            $printer->error($e->getMessage());

            return ExitCode::Usage->value;
        }

        return $code->value;
    }

    /** @return callable(string, ?string, float): ApiClientInterface */
    private function defaultClientFactory(): callable
    {
        return static fn (
            string $apiUrl,
            ?string $token,
            float $timeoutSeconds = ApiClient::DEFAULT_TIMEOUT_SECONDS,
        ): ApiClientInterface => new ApiClient($apiUrl, $token, timeoutSeconds: $timeoutSeconds);
    }

    private function profile(InputInterface $input): ?string
    {
        $profile = $input->getOption('profile');

        return is_string($profile) && $profile !== '' ? $profile : null;
    }

    private function token(InputInterface $input): ?string
    {
        $token = $input->getOption('token');
        if (is_string($token) && $token !== '') {
            return $token;
        }

        $env = $_ENV[CredentialsStore::ENV_TOKEN] ?? getenv(CredentialsStore::ENV_TOKEN);

        return is_string($env) && $env !== '' ? $env : null;
    }

    /** Adres: flaga → zmienna środowiskowa → zapisane poświadczenie. */
    private function apiUrl(InputInterface $input): ?string
    {
        $flag = $input->getOption('api-url');
        if (is_string($flag) && trim($flag) !== '') {
            return trim($flag);
        }

        $env = $_ENV[CredentialsStore::ENV_API_URL] ?? getenv(CredentialsStore::ENV_API_URL);
        if (is_string($env) && trim($env) !== '') {
            return trim($env);
        }

        return $this->store->load($this->profile($input))?->apiUrl;
    }
}
