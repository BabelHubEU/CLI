<?php

declare(strict_types=1);

namespace BabelHub\Cli\Console\Command;

use BabelHub\Cli\Config\Credentials;
use BabelHub\Cli\Config\CredentialsStore;
use BabelHub\Cli\Console\Application;
use BabelHub\Cli\ExitCode;
use BabelHub\Cli\Http\ApiClient;
use BabelHub\Cli\Http\ApiClientInterface;
use BabelHub\Cli\Http\ApiException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `babelhub whoami` — kim jestem, do czego mam prawo i którym poświadczeniem mówię.
 *
 * Pierwsze polecenie, które uruchamia się po „mam 403 i nie wiem czemu”. Odpowiada na trzy
 * pytania naraz, bo wszystkie trzy są potrzebne do diagnozy:
 *
 * * **człowiek czy aplikacja** (`authenticated_as`) — od tego zależy, gdzie szukać uprawnień;
 * * **jaka organizacja i jakie zakresy** — brak `translations:write` wyjaśnia `403` przy `push`
 *   lepiej niż jakikolwiek log serwera;
 * * **który token** (nazwa, data ważności) — bo najczęstszą przyczyną jest token, o którym
 *   właściciel zapomniał, że go ma.
 *
 * Przy okazji **odświeżamy zapisane metadane** poświadczenia: dzięki temu `status` czy `pull`
 * nie muszą dopytywać o organizację, a zmiana nazwy konta nie zostawia w pliku starej nazwy.
 */
#[AsCommand(name: 'whoami', description: 'Pokazuje, kim CLI jest zalogowane i jakie ma uprawnienia')]
final class WhoamiCommand extends Command
{
    /** @param callable(string, ?string, float): ApiClientInterface|null $clientFactory */
    public function __construct(
        private readonly CredentialsStore $store,
        private $clientFactory = null,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $printer = Application::printer($input, $output);
        $profileOption = $input->getOption('profile');
        $profile = is_string($profileOption) && $profileOption !== '' ? $profileOption : null;

        $tokenFlag = $input->getOption('token');
        $credentials = $this->store->resolve(
            tokenFromFlag: is_string($tokenFlag) ? $tokenFlag : null,
            apiUrlFromFlag: is_string($input->getOption('api-url')) ? (string) $input->getOption('api-url') : null,
            profile: $profile,
        );

        if ($credentials === null) {
            $printer->error(sprintf(
                'Brak poświadczenia. Uruchom `babelhub login` (parowanie w przeglądarce) ' .
                'albo ustaw %s dla CI. Plik poświadczeń: %s.',
                CredentialsStore::ENV_TOKEN,
                $this->store->path(),
            ));

            return ExitCode::Usage->value;
        }

        try {
            $client = ($this->clientFactory ?? $this->defaultClientFactory())(
                $credentials->apiUrl,
                $credentials->token,
                Application::timeoutSeconds($input),
            );
            $response = $client->get('/api/v1/me');
        } catch (ApiException $e) {
            $printer->error($e->getMessage());

            return $e->exitCode()->value;
        }

        if (!$response->isSuccess()) {
            $printer->error($response->message());

            return $response->exitCode()->value;
        }

        $me = $response->json();
        $this->store->save($this->updated($credentials, $me), $profile);

        $organization = is_array($me['organization'] ?? null) ? $me['organization'] : [];
        $user = is_array($me['user'] ?? null) ? $me['user'] : null;
        $token = is_array($me['token'] ?? null) ? $me['token'] : null;
        $application = is_array($me['application'] ?? null) ? $me['application'] : null;
        $scopes = array_values(array_filter((array) ($me['scopes'] ?? []), 'is_string'));

        $printer->json($me);

        // Nagłówek to dekoracja (`result()` — tylko `text`), a **pola idą przez `plain()`**,
        // czyli są też w trybie `plain`. Wcześniej wszystkie linie szły przez `result()`, więc
        // `whoami --output=plain` nie wypisywało **nic** — a to jedyny tryb, w którym
        // `grep zakresy` ma sens. `status` robi to od początku właśnie tak (patrz `StatusCommand`).
        $printer->result(sprintf('<info>%s</info>', (string) ($organization['name'] ?? '—')));
        $printer->plain(sprintf('  serwis:      %s', $credentials->apiUrl));
        $printer->plain(sprintf('  poświadczenie: %s', (string) ($me['authenticated_as'] ?? '—')));
        if ($user !== null) {
            $printer->plain(sprintf('  konto:       %s (%s)', (string) ($user['display_name'] ?? '—'), (string) ($user['email'] ?? $user['username'] ?? '—')));
        }
        if ($application !== null) {
            $printer->plain(sprintf('  aplikacja:   %s', (string) ($application['slug'] ?? '—')));
        }
        if ($token !== null) {
            $printer->plain(sprintf('  token:       %s (ważny do: %s)', (string) ($token['name'] ?? '—'), (string) ($token['expires_at'] ?? 'bez terminu')));
        }
        $printer->plain('  zakresy:     ' . ($scopes === [] ? '—' : implode(', ', $scopes)));

        return ExitCode::Success->value;
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

    /**
     * @param array<string, mixed> $me
     */
    private function updated(Credentials $credentials, array $me): Credentials
    {
        $user = is_array($me['user'] ?? null) ? $me['user'] : [];
        $organization = is_array($me['organization'] ?? null) ? $me['organization'] : [];
        $scopes = [];
        foreach ((array) ($me['scopes'] ?? []) as $scope) {
            if (is_string($scope)) {
                $scopes[] = $scope;
            }
        }

        $display = $user['display_name'] ?? $user['email'] ?? $user['username'] ?? null;

        return new Credentials(
            apiUrl: $credentials->apiUrl,
            token: $credentials->token,
            user: is_string($display) ? $display : $credentials->user,
            organization: is_string($organization['name'] ?? null) ? $organization['name'] : $credentials->organization,
            scopes: $scopes !== [] ? $scopes : $credentials->scopes,
        );
    }
}
