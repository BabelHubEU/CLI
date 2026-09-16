<?php

declare(strict_types=1);

namespace BabelHub\Cli\Application;

use BabelHub\Cli\Config\Credentials;
use BabelHub\Cli\Config\CredentialsStore;
use BabelHub\Cli\Config\PendingPairing;
use BabelHub\Cli\Config\PendingPairingStore;
use BabelHub\Cli\Console\BrowserInterface;
use BabelHub\Cli\ExitCode;
use BabelHub\Cli\Http\ApiClientInterface;
use BabelHub\Cli\Http\ApiResponse;
use BabelHub\Cli\Output\Printer;
use BabelHub\Cli\Version;

/**
 * Logowanie: parowanie w przeglądarce, `--complete` dla CI i `--token` dla kont serwisowych.
 *
 * Wydzielone z komendy, bo to **jedyna droga, którą do serwisu trafia poświadczenie człowieka**,
 * i musi być sprawdzalna bez sieci. Trzy warianty, jeden cel — w każdym z nich kończymy
 * zapisem poświadczenia po stronie użytkownika (pliku z prawami 0600), nigdy w repozytorium.
 *
 * ### Dlaczego nie „wklej token ze strony ustawień”
 *
 * Bo to najczęstsza droga, którą poświadczenie trafia w złe miejsce: człowiek kopiuje token
 * do schowka, wkleja go do pliku w repo i commituje. Parowanie odwraca kierunek — **terminal
 * prosi, człowiek zatwierdza w przeglądarce, sekret wraca do terminala**, który o niego poprosił.
 * Nikt niczego nie kopiuje, więc nie ma czego wkleić w złe miejsce.
 *
 * ### Bez TTY (CI)
 *
 * Ten sam mechanizm wypisuje JSON `{browser_url, verification_code, next_step}` i kończy się
 * kodem `7` („czeka na człowieka”) — a po zatwierdzeniu w przeglądarce wystarczy powtórzyć
 * polecenie z `--complete=<url>`, żeby odebrać poświadczenie. Kod `0` w tym miejscu byłby
 * kłamstwem: poświadczenia jeszcze nie ma, a skrypt, który by w to uwierzył, poleciałby dalej
 * bez dostępu.
 */
final readonly class CliLogin
{
    public function __construct(
        private ApiClientInterface $client,
        private CredentialsStore $store,
        private PendingPairingStore $pending,
        private Printer $printer,
        private BrowserInterface $browser,
        private Sleeper $sleeper,
    ) {
    }

    /**
     * `--token`: weryfikacja i zapis. Nie ufamy tokenowi „w ciemno” — poświadczenie, które
     * nie działa, zapisane bez sprawdzenia, objawia się dopiero przy następnym poleceniu,
     * i to komunikatem, który nie mówi, że problem jest w tokenie.
     */
    public function withToken(string $token, ?string $profile = null): ExitCode
    {
        $this->printer->info('Sprawdzam token…');
        $response = $this->client->withToken($token)->get('/api/v1/me');

        if (!$response->isSuccess()) {
            $this->printer->error($response->message());

            return $response->exitCode();
        }

        $me = $response->json();
        $this->store->save($this->credentialsFrom($me, $token), $profile);

        // Wynik również maszynowo: `login --token` w CI to jedyne miejsce, w którym skrypt
        // dowiaduje się, **jako kto** się zalogował. Bez tego `--output=json` nie wypisywał
        // niczego, więc skrypt musiał ufać samemu kodowi wyjścia (1.17).
        $this->printer->json([
            'logged_in' => true,
            'authenticated_as' => $me['authenticated_as'] ?? null,
            'organization' => $this->organization($me),
            'credentials_path' => $this->store->path(),
            'profile' => $profile ?? CredentialsStore::DEFAULT_PROFILE,
        ]);

        $this->printer->info(sprintf(
            'Zalogowano jako %s w organizacji %s. Poświadczenie zapisane w %s (prawa 0600).',
            $this->who($me),
            $this->organization($me),
            $this->store->path(),
        ));

        return ExitCode::Success;
    }

    /** Pierwszy krok parowania: prosimy serwis o kod dla tego terminala. */
    public function startPairing(
        bool $openBrowser,
        ?string $profile = null,
        int $waitSeconds = 300,
        bool $wait = true,
    ): ExitCode {
        $response = $this->client->post('/api/v1/cli/device', [
            'client_name' => Version::clientName(),
        ]);

        if (!$response->isSuccess()) {
            $this->printer->error($response->message());

            return $response->exitCode();
        }

        $payload = $response->json();
        $deviceCode = $this->string($payload, 'device_code');
        $userCode = $this->string($payload, 'user_code');
        $browserUrl = $this->string($payload, 'verification_uri_complete');
        $interval = (int) ($payload['interval'] ?? 5);
        $expiresIn = (int) ($payload['expires_in'] ?? 600);

        if ($deviceCode === null || $userCode === null || $browserUrl === null) {
            $this->printer->error('Serwis nie zwrócił kompletu danych parowania — sprawdź, czy adres wskazuje na BabelHub.');

            return ExitCode::Failure;
        }

        // Stan parowania ląduje lokalnie (prawa 0600), bo tylko tak `--complete` może odebrać
        // poświadczenie bez wypisywania sekretu terminala na ekran i do logów CI.
        $expiresAt = (new \DateTimeImmutable())->modify(sprintf('+%d seconds', max(60, $expiresIn)));
        $this->pending->save(new PendingPairing(
            deviceCode: $deviceCode,
            userCode: $userCode,
            browserUrl: $browserUrl,
            apiUrl: $this->client->baseUrl(),
            expiresAt: $expiresAt === false ? new \DateTimeImmutable('+10 minutes') : $expiresAt,
        ));

        // Bez TTY — a także wtedy, gdy ktoś wprost poprosił o `--no-wait` — nie ma na co czekać
        // w pętli: wypisujemy to, co potrzebne do dokończenia z `--complete`, i kończymy kodem
        // „czeka na człowieka”. Sam brak TTY nie wystarcza jako kryterium: `docker exec` daje
        // terminal, a `--no-browser` w skrypcie nie znaczy „zostań i czekaj”.
        if ((!$this->isInteractive() && !$openBrowser) || !$wait) {
            $this->printer->json([
                'browser_url' => $browserUrl,
                'verification_code' => $userCode,
                'next_step' => sprintf('babelhub login --complete=%s', $browserUrl),
                'expires_in' => $expiresIn,
            ]);
            $this->printer->info(sprintf('Otwórz %s i zatwierdź kod %s.', $browserUrl, $userCode));
            $this->printer->info(sprintf('Potem dokończ: babelhub login --complete=%s', $browserUrl));

            return ExitCode::Pending;
        }

        $this->printer->result('');
        $this->printer->result(sprintf('Kod parowania: <info>%s</info>', $userCode));
        $this->printer->result(sprintf('Zatwierdź go w panelu: %s', $browserUrl));
        $this->printer->result('');

        if ($openBrowser && !$this->browser->open($browserUrl)) {
            // Brak przeglądarki nie jest błędem: adres i kod są już wypisane.
            $this->printer->warn('Nie udało się otworzyć przeglądarki — otwórz adres ręcznie.');
        }

        return $this->awaitCredential($deviceCode, $interval, $waitSeconds, $profile);
    }

    /**
     * Dokończenie parowania zaczętego wcześniej (CI, druga maszyna).
     *
     * Adres z przeglądarki jest **potwierdzeniem, który kod** człowiek zatwierdził — nie
     * nośnikiem poświadczenia: `device_code` (sekret terminala) nigdy nie opuszcza tej maszyny
     * i leży w pliku stanu parowania z prawami 0600. Bez tego rozróżnienia kod z adresu
     * wystarczałby do odebrania cudzego tokenu, a kod z adresu trafia do historii przeglądarki.
     */
    public function completePairing(?string $url, int $waitSeconds = 120, ?string $profile = null): ExitCode
    {
        $pairing = $this->pending->loadValid();
        if ($pairing === null) {
            $this->printer->error(
                'Nie ma zaczętego parowania do dokończenia (albo już wygasło). Uruchom `babelhub login`, żeby zacząć nowe.'
            );

            return ExitCode::Usage;
        }

        if ($url !== null && $url !== '') {
            $code = $this->codeFromUrl($url);
            if ($code === null) {
                $this->printer->error('Adres nie zawiera kodu parowania (spodziewam się parametru „code”).');

                return ExitCode::Usage;
            }

            if (strcasecmp($code, $pairing->userCode) !== 0) {
                // Zatwierdzono inny kod, niż czeka na tej maszynie — nie ma czego odbierać.
                $this->printer->error(sprintf(
                    'Ten adres dotyczy kodu %s, a tu czeka na %s. Zatwierdź właściwy kod albo zacznij parowanie od nowa.',
                    strtoupper($code),
                    $pairing->userCode,
                ));

                return ExitCode::Usage;
            }
        }

        return $this->awaitCredential($pairing->deviceCode, 5, $waitSeconds, $profile);
    }

    /** Kod z `verification_uri_complete` — akceptujemy też goły kod podany zamiast adresu. */
    private function codeFromUrl(string $url): ?string
    {
        $query = parse_url($url, PHP_URL_QUERY);
        if (is_string($query)) {
            $params = [];
            parse_str($query, $params);
            $code = $params['code'] ?? null;
            if (is_string($code) && trim($code) !== '') {
                return trim($code);
            }
        }

        return str_contains($url, '-') && !str_contains($url, '/') ? trim($url) : null;
    }

    /**
     * Pętla odpytywania: kończy się **zawsze** — poświadczeniem, odmową, wygaśnięciem albo
     * upływem czasu. Narzędzie, które w tym miejscu potrafi zawisnąć bez końca, jest gorsze
     * niż brak narzędzia, bo blokuje pipeline.
     */
    private function awaitCredential(string $deviceCode, int $interval, int $waitSeconds, ?string $profile): ExitCode
    {
        $interval = max(1, $interval);
        $deadline = microtime(true) + max(1, $waitSeconds);

        $this->printer->info('Czekam na zatwierdzenie w przeglądarce…');

        while (microtime(true) < $deadline) {
            $response = $this->client->post('/api/v1/cli/token', ['device_code' => $deviceCode]);

            if (!$response->isSuccess()) {
                $this->printer->error($response->message());

                return $response->exitCode();
            }

            $payload = $response->json();
            $status = (string) ($payload['status'] ?? '');

            if ($status === 'ready') {
                $token = $this->string($payload, 'token');
                if ($token === null) {
                    $this->printer->error('Serwis zgłosił gotowe poświadczenie, ale go nie przysłał.');

                    return ExitCode::Failure;
                }

                // Token z parowania już istnieje w bazie, więc nie ma po co pytać `/me` drugi raz —
                // dane przyszły razem z nim.
                $this->store->save($this->credentialsFrom($payload, $token), $profile);
                // Stan parowania kasujemy od razu: trzymanie sekretu terminala dłużej, niż to
                // konieczne, nie ma żadnego uzasadnienia.
                $this->pending->forget();

                $this->printer->info(sprintf(
                    'Zalogowano jako %s w organizacji %s. Poświadczenie zapisane w %s (prawa 0600).',
                    $this->who($payload),
                    $this->organization($payload),
                    $this->store->path(),
                ));

                return ExitCode::Success;
            }

            if ($status === 'denied') {
                $this->pending->forget();
                $this->printer->error('Parowanie zostało odrzucone w panelu. Nie powstało żadne poświadczenie.');

                return ExitCode::Unauthorized;
            }

            if (in_array($status, ['expired', 'consumed'], true)) {
                $this->pending->forget();
                $this->printer->error('Kod parowania wygasł albo został już wykorzystany. Uruchom `babelhub login` ponownie.');

                return ExitCode::Unauthorized;
            }

            if ($status !== 'pending' && $status !== '') {
                $this->printer->error(sprintf('Nieoczekiwany stan parowania: %s.', $status));

                return ExitCode::Failure;
            }

            $this->sleeper->sleep((float) $interval);
        }

        $this->printer->error(sprintf(
            'Nie doczekałem się zatwierdzenia w ciągu %d s. Poświadczenia nie ma — możesz powtórzyć polecenie później.',
            $waitSeconds,
        ));

        return ExitCode::Pending;
    }

    /** @param array<string, mixed> $payload */
    private function credentialsFrom(array $payload, string $token): Credentials
    {
        $scopes = [];
        foreach ((array) ($payload['scopes'] ?? []) as $scope) {
            if (is_string($scope)) {
                $scopes[] = $scope;
            }
        }

        return new Credentials(
            apiUrl: $this->client->baseUrl(),
            token: $token,
            user: $this->who($payload),
            organization: $this->organization($payload),
            scopes: $scopes,
        );
    }

    /** @param array<string, mixed> $payload */
    private function who(array $payload): string
    {
        $user = $payload['user'] ?? null;
        if (is_array($user)) {
            $display = $user['display_name'] ?? null;
            if (is_string($display) && $display !== '') {
                return $display;
            }
            $username = $user['username'] ?? null;
            if (is_string($username) && $username !== '') {
                return $username;
            }
        }

        return 'konto serwisowe';
    }

    /** @param array<string, mixed> $payload */
    private function organization(array $payload): string
    {
        $organization = $payload['organization'] ?? null;
        if (is_array($organization)) {
            $name = $organization['name'] ?? null;
            if (is_string($name) && $name !== '') {
                return $name;
            }
        }

        return '—';
    }

    /** @param array<string, mixed> $payload */
    private function string(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function isInteractive(): bool
    {
        if (!defined('STDOUT')) {
            return false;
        }

        return function_exists('stream_isatty') && @stream_isatty(STDOUT);
    }
}
