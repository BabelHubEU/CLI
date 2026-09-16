<?php

declare(strict_types=1);

namespace BabelHub\Cli\Http;

/**
 * To, czego CLI potrzebuje od transportu: dwa żądania i adres.
 *
 * Interfejs istnieje po to, żeby **całe zachowanie CLI dało się przetestować bez sieci** —
 * a testy `tests/Cli/` mają sprawdzać rzeczy, których nikt inaczej nie sprawdzi: kod wyjścia
 * przy limicie żądań, brak `0` przy powodzeniu częściowym, atomowość zapisu plików. Test,
 * który do tego potrzebuje działającego serwisu, jest testem integracyjnym, a nie bramką CI.
 */
interface ApiClientInterface
{
    /**
     * @param array<string, string|int> $query
     * @param array<string, string> $headers dodatkowe nagłówki żądania — dziś używa tego
     *   `pull` (`If-None-Match` z wersją z locka), żeby **nie dotykać plików**, gdy katalog
     *   się nie zmienił; bez tego każdy przebieg w CI przepisywałby pliki i robił diff z niczego
     */
    public function get(string $path, array $query = [], array $headers = []): ApiResponse;

    /** @param array<string, mixed> $payload */
    public function post(string $path, array $payload = []): ApiResponse;

    public function baseUrl(): string;

    /**
     * Ten sam klient, ale z innym poświadczeniem.
     *
     * Potrzebne dokładnie raz i dlatego jest w interfejsie: `login --token` musi **najpierw**
     * sprawdzić token, którego jeszcze nie zapisał — a klient zbudowany dla starego
     * poświadczenia nie może tego zrobić bez podmiany nagłówka.
     */
    public function withToken(?string $token): ApiClientInterface;
}
