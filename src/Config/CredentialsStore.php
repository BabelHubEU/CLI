<?php

declare(strict_types=1);

namespace BabelHub\Cli\Config;

use BabelHub\Cli\ExitCode;
use BabelHub\Cli\CliIoException;
use RuntimeException;

/**
 * Magazyn poświadczeń **poza repozytorium**, z udokumentowaną kolejnością źródeł.
 *
 * Kolejność (od najważniejszego): flaga `--token` → zmienna `BABELHUB_TOKEN` → plik
 * użytkownika. Jest tak nieprzypadkowo: w CI poświadczenie podaje się zmienną środowiskową
 * (nikt nie commituje pliku), lokalnie — plikiem, który powstał z parowania w przeglądarce
 * i nigdy nie przechodzi przez schowek. Flaga zostaje dla skryptów i kont serwisowych, ale
 * **nie jest domyślną drogą**: token w linii poleceń trafia do historii powłoki i do listy
 * procesów.
 *
 * Plik ma prawa `0600` (na Windows `chmod` jest w praktyce dekoracją — dlatego dokumentacja
 * mówi wprost, że na Windows poświadczenie chroni katalog profilu, a nie prawa pliku).
 */
final class CredentialsStore
{
    public const ENV_TOKEN = 'BABELHUB_TOKEN';
    public const ENV_API_URL = 'BABELHUB_API_URL';
    public const ENV_PROFILE = 'BABELHUB_PROFILE';
    public const DEFAULT_PROFILE = 'default';

    public function __construct(private readonly string $path)
    {
    }

    /**
     * Domyślna lokalizacja: `$XDG_CONFIG_HOME/babelhub/credentials.json`, a gdy go nie ma —
     * `~/.config/...`. Na Windows `HOME` bywa nieustawione, więc schodzimy do `APPDATA`
     * (i dalej `USERPROFILE`), żeby CLI działało także w PowerShellu bez konfiguracji.
     */
    public static function defaultPath(): string
    {
        $xdg = self::env('XDG_CONFIG_HOME');
        if ($xdg !== null) {
            return rtrim($xdg, '/\\') . '/babelhub/credentials.json';
        }

        $home = self::env('HOME') ?? self::env('USERPROFILE');
        if ($home !== null) {
            return rtrim($home, '/\\') . '/.config/babelhub/credentials.json';
        }

        $appData = self::env('APPDATA');
        if ($appData !== null) {
            return rtrim($appData, '/\\') . '/babelhub/credentials.json';
        }

        throw new RuntimeException('Nie wiem, gdzie zapisać poświadczenie: ustaw HOME, XDG_CONFIG_HOME albo APPDATA.');
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Poświadczenie z kolejności źródeł.
     *
     * @param string|null $tokenFromFlag token podany flagą (najwyższy priorytet)
     * @param string|null $apiUrlFromFlag adres podany flagą
     */
    public function resolve(?string $tokenFromFlag = null, ?string $apiUrlFromFlag = null, ?string $profile = null): ?Credentials
    {
        $stored = $this->load($profile);

        $token = $this->firstNonEmpty($tokenFromFlag, self::env(self::ENV_TOKEN), $stored?->token);
        $apiUrl = $this->firstNonEmpty($apiUrlFromFlag, self::env(self::ENV_API_URL), $stored?->apiUrl);

        if ($token === null || $apiUrl === null) {
            return null;
        }

        // `$stored` **może** tu być `null`, mimo że token i adres są znane: token może przyjść
        // z flagi, a adres ze zmiennej środowiskowej, i wtedy nie ma żadnego pliku. Analiza
        // statyczna twierdzi inaczej (widzi `?->token` jako źródło) — a ślepe zaufanie temu
        // dałoby dereferencję `null` dokładnie w scenariuszu „CI z tokenem w zmiennej”.
        $metadata = $stored ?? new Credentials(apiUrl: $apiUrl, token: $token);

        return new Credentials(
            apiUrl: $apiUrl,
            token: $token,
            user: $stored?->user,
            organization: $stored?->organization,
            scopes: $metadata->scopes,
        );
    }

    public function load(?string $profile = null): ?Credentials
    {
        $profiles = $this->read();
        $entry = $profiles[$profile ?? self::env(self::ENV_PROFILE) ?? self::DEFAULT_PROFILE] ?? null;

        return is_array($entry) ? Credentials::fromArray($entry) : null;
    }

    public function save(Credentials $credentials, ?string $profile = null): void
    {
        $profiles = $this->read();
        $profiles[$profile ?? self::env(self::ENV_PROFILE) ?? self::DEFAULT_PROFILE] = $credentials->toArray();

        $this->write($profiles);
    }

    /**
     * Wylogowanie: kasujemy **tylko profil**, nie cały plik — ktoś może mieć osobne
     * poświadczenie do serwisu produkcyjnego i lokalnego, a `logout` na jednym nie może
     * wylogowywać z drugiego.
     */
    public function forget(?string $profile = null): bool
    {
        $profiles = $this->read();
        $key = $profile ?? self::env(self::ENV_PROFILE) ?? self::DEFAULT_PROFILE;

        if (!array_key_exists($key, $profiles)) {
            return false;
        }

        unset($profiles[$key]);

        if ($profiles === []) {
            if (is_file($this->path)) {
                @unlink($this->path);
            }

            return true;
        }

        $this->write($profiles);

        return true;
    }

    /** @return array<string, mixed> */
    private function read(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        $raw = file_get_contents($this->path);
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf(
                'Plik poświadczeń %s nie jest poprawnym JSON-em. Usuń go i zaloguj się ponownie.',
                $this->path,
            ));
        }

        /** @var array<string, mixed> $profiles */
        $profiles = $decoded['profiles'] ?? $decoded;

        return $profiles;
    }

    /** @param array<string, mixed> $profiles */
    private function write(array $profiles): void
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new CliIoException(sprintf('Nie mogę utworzyć katalogu %s na poświadczenie.', $directory));
        }

        $encoded = json_encode(['profiles' => $profiles], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new CliIoException('Nie udało się zapisać poświadczenia.');
        }

        if (file_put_contents($this->path, $encoded . "\n") === false) {
            throw new CliIoException(sprintf('Nie udało się zapisać poświadczenia w %s.', $this->path));
        }

        // Prawa 0600: plik z tokenem nie może być czytelny dla innych kont na tej maszynie.
        @chmod($this->path, 0600);
    }

    private function firstNonEmpty(?string ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if ($candidate !== null && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    private static function env(string $name): ?string
    {
        $value = $_ENV[$name] ?? getenv($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** Kod wyjścia dla braku poświadczenia — jedno miejsce, żeby komunikat i kod były spójne. */
    public static function missingCredentialsExitCode(): ExitCode
    {
        return ExitCode::Usage;
    }
}
