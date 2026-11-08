<?php

declare(strict_types=1);

namespace BabelHub\Cli\Http;

use BabelHub\Cli\ExitCode;

/**
 * Odpowiedź API w postaci, z którą da się pracować bez zgadywania.
 *
 * Cztery rzeczy, które CLI musi umieć z niej wyczytać — i wszystkie są tu, bo każda z nich
 * była gdzieś źródłem cichego błędu w narzędziach tej kategorii:
 *
 * 1. **kod HTTP** (decyzja „powtórzyć, poprawić, poczekać”),
 * 2. **kod błędu z ciała** (`{"error": "rate_limited"}`) — to on jest kontraktem, nie kod HTTP,
 * 3. **zdanie z ciała** (`{"message": …}`) — użytkownik ma przeczytać zdanie serwisu,
 *    a nie „HTTP 422”,
 * 4. **`Retry-After`** — bez niego „poczekaj” nie mówi, ile.
 */
final readonly class ApiResponse
{
    /** @param array<string, list<string>> $headers */
    public function __construct(
        public int $status,
        public string $body,
        public array $headers = [],
    ) {
    }

    /**
     * Czy odpowiedź znaczy „zrobione”.
     *
     * `3xx` liczymy jako sukces, bo jedyne, co tu realnie występuje, to **`304 Not Modified`**
     * z manifestu i katalogu (klient pyta „czy mam aktualne?”, a serwis odpowiada „tak”) —
     * czyli odpowiedź poprawna, tylko pusta. `2xx` to reszta sukcesów. Cokolwiek od `400`
     * w górę jest błędem i **nigdy** nie może dać kodu `0` (1.16 planu).
     */
    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 400;
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** Kontrakt API: `{"error": "…"}`. Pusty, gdy serwis odpowiedział czymś innym (proxy, HTML). */
    public function errorCode(): string
    {
        $error = $this->json()['error'] ?? null;

        return is_string($error) && $error !== '' ? $error : '';
    }

    /**
     * Zdanie do pokazania człowiekowi: z ciała odpowiedzi, a gdy go nie ma — z kodu HTTP.
     *
     * Dwa kody projektów próbnych dostają zdanie **od CLI**, nie od serwisu: serwis mówi po
     * angielsku i nie wie, że klient to terminal, a te dwa przypadki wymagają od człowieka
     * konkretnej czynności („załóż konto i odbierz projekt"). Tłumaczenie ich tutaj trzyma
     * podpowiedź w jednym miejscu, więc `push`, `pull`, `status` i `validate` powiedzą to samo.
     */
    public function message(): string
    {
        $trial = $this->trialHint();
        if ($trial !== null) {
            return $trial;
        }

        $message = $this->json()['message'] ?? null;
        if (is_string($message) && trim($message) !== '') {
            return trim($message);
        }

        return $this->errorCode() !== ''
            ? sprintf('%s (HTTP %d)', $this->errorCode(), $this->status)
            : sprintf('HTTP %d', $this->status);
    }

    /**
     * Podpowiedź dla dwóch kodów projektu próbnego; `null` dla pozostałych odpowiedzi.
     *
     * `trial_expired` znaczy „projekt wygasł" — jedyną sensowną reakcją jest założenie konta
     * i odebranie projektu, a nie ponawianie. `trial_key_limit` znaczy „próba nie jest do pracy
     * z takim projektem" — ten sam wniosek. Dlatego mówimy wprost, gdzie iść.
     */
    private function trialHint(): ?string
    {
        return match ($this->errorCode()) {
            'trial_expired' => 'Projekt próbny wygasł. Załóż konto w panelu i odbierz projekt (/trial/claim) — przeniesiemy do niego tłumaczenia.',
            'trial_key_limit' => 'Projekt próbny jest ograniczony do 200 kluczy. Załóż konto i odbierz projekt (/trial/claim), żeby pushować dalej.',
            default => null,
        };
    }

    /** Ile sekund kazał czekać serwis (`Retry-After`), o ile w ogóle powiedział. */
    public function retryAfter(): ?int
    {
        $value = $this->header('Retry-After');
        if ($value === null || !preg_match('/^\d+$/', trim($value))) {
            return null;
        }

        return (int) trim($value);
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $header => $values) {
            if (strcasecmp($header, $name) === 0) {
                return $values[0] ?? null;
            }
        }

        return null;
    }

    /**
     * Kod wyjścia dla nieudanej odpowiedzi — **jedno miejsce** na to mapowanie.
     *
     * Dzięki temu każda komenda kończy się tak samo, a tabela z `ExitCode` ma jednego
     * wykonawcę. Rozproszenie tego po komendach kończy się tym, że jedna z nich zwraca `1`
     * tam, gdzie reszta zwraca `4` — i skrypt CI, który rozróżnia limit od błędu, przestaje
     * działać tylko dla niektórych poleceń.
     */
    public function exitCode(): ExitCode
    {
        if ($this->isSuccess()) {
            return ExitCode::Success;
        }

        return match (true) {
            $this->status === 401, $this->status === 403 => ExitCode::Unauthorized,
            $this->status === 429 => ExitCode::RateLimited,
            $this->status >= 400 && $this->status < 500 => ExitCode::Rejected,
            default => ExitCode::Failure,
        };
    }
}
