<?php

declare(strict_types=1);

namespace BabelHub\Cli\Application;

use BabelHub\Cli\CliIoException;

/**
 * `download.post_process_command` — komenda projektu uruchamiana po zapisaniu plików (1.19).
 *
 * Plan nazywa to „dociągnięciem formatowania własnym skryptem”: `pull` pisze pliki tak, jak
 * wyglądały (1.16b), ale projekt może mieć dodatkowe reguły — `prettier`, `php-cs-fixer`,
 * własny skrypt sortujący klucze. Ten hook jest miejscem, w którym one wchodzą, **zamiast**
 * drugiego kroku w każdym CI z osobna.
 *
 * Cztery decyzje, każda z powodem:
 *
 * 1. **Uruchamiamy powłoką (`proc_open` ze stringiem), w katalogu projektu.** To ta sama
 *    kategoria zaufania, co `composer.json` (``scripts``), `Makefile` czy `npm run`: komenda
 *    pochodzi z repozytorium, które i tak wykonuje się w CI. Alternatywa — rozbijanie napisu
 *    na argumenty bez powłoki — odebrałaby możliwość napisania `a && b` i wyglądałaby na
 *    bezpieczeństwo, którego nie daje.
 * 2. **Nic z danych nie trafia do komendy.** Żaden klucz, język ani fragment odpowiedzi serwisu
 *    nie jest podstawiany — inaczej konfiguracja stawałaby się miejscem wstrzyknięcia kodu
 *    (tekst tłumaczenia pochodzi od ludzi i z zewnętrznych plików).
 * 3. **Limit czasu, bo hook wiesza pipeline.** Skrypt czekający na wejście z terminala w CI
 *    wisi **wiecznie** — a to jest dokładnie ten rodzaj awarii, którego nie widać w logu.
 *    Po przekroczeniu limitu proces jest ubijany, a komunikat mówi wprost, że pliki zostały
 *    zapisane, ale niesformatowane. Limit ubija **drzewo** procesów (na Windows `taskkill /T`)
 *    i **nie czeka** na domknięcie potoków: ubicie samej powłoki zostawiało hook przy życiu,
 *    a wraz z nim otwarte potoki — czyli przywracało czekanie w nieskończoność (patrz
 *    `killTree()` i `run()`).
 * 4. **Wynik pokazujemy dopiero przy niepowodzeniu.** Przy sukcesie hook ma milczeć (jego
 *    efektem są pliki, a nie log), przy błędzie — całe wyjście, bo tam jest jedyne zdanie,
 *    które wyjaśnia, co się stało (`command not found`, brak zależności, zły glob).
 */
final readonly class PostProcessRunner
{
    /**
     * Górna granica pracy hook-a (sekundy).
     *
     * 5 minut: formatowanie plików językowych jest lokalne i szybkie, a wszystko, co trwa dłużej,
     * znaczy „skrypt czeka na coś, czego w CI nie dostanie”. Ta sama filozofia co przy
     * `--timeout`: narzędzie, które wiesza pipeline na zawsze, jest w CI gorsze niż jego brak.
     */
    public const TIMEOUT_SECONDS = 300;

    /** Porcja jednego odczytu z potoku hook-a (bajty). */
    private const READ_CHUNK = 8192;

    /** Ile bajtów zbieramy w jednym przebiegu pętli — patrz `drain()`, punkt 3. */
    private const READ_BUDGET = 262144;

    /**
     * Limit jest **stanem obiektu**, a nie parametrem `run()`: dzięki temu da się go podmienić
     * w teście (sekunda zamiast pięciu minut), a komenda nie musi wiedzieć, ile wolno czekać.
     */
    public function __construct(private int $timeoutSeconds = self::TIMEOUT_SECONDS)
    {
    }

    public function timeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    /**
     * @return array{exit_code: int, output: string}
     *
     * @throws CliIoException gdy procesu nie da się w ogóle uruchomić (brak `proc_open` w `php.ini`)
     */
    public function run(string $command, string $directory): array
    {
        // `proc_open` bywa wyłączony (`disable_functions` w hostingach współdzielonych) — wtedy
        // PHP nie zna tej funkcji i nie ma czego wołać. Milczące pominięcie hook-a byłoby
        // kłamstwem: CI zacommitowałoby plik **bez** obiecanego formatowania.
        if (!function_exists('proc_open')) {
            throw new CliIoException(sprintf(
                'Nie mogę uruchomić komendy „%s”: funkcja proc_open jest wyłączona w php.ini (disable_functions). ' .
                'Pliki zostały zapisane, ale NIE zostały przetworzone.',
                $command,
            ));
        }

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes, $directory);
        if (!is_resource($process)) {
            throw new CliIoException(sprintf('Nie udało się uruchomić komendy „%s”.', $command));
        }

        // `stream_set_blocking(false)` **nie działa na potokach z `proc_open` na Windows**
        // (zwraca `false` i nic nie zmienia), więc NIE polegamy na nim: czytamy wyłącznie to,
        // co `stream_select` zgłosi jako gotowe, i to po kawałku `fread`. Powód jest zmierzony:
        // `stream_get_contents` czyta **do końca strumienia**, więc na Windows czekał na wyjście
        // hooka — cały limit był wtedy fikcją (test wiszącego hooka: 30,2 s przy limicie 1 s),
        // mimo że pętla poprawnie liczyła czas. Na Linuksie tryb nieblokujący działa, dlatego
        // CI tego nie widziało.
        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }

        $output = '';
        $deadline = microtime(true) + $this->timeoutSeconds;
        $timedOut = false;

        while (true) {
            $status = proc_get_status($process);
            $output .= $this->drain($pipes);

            if (!$status['running']) {
                break;
            }

            if (microtime(true) > $deadline) {
                $timedOut = true;
                $this->killTree($process);
                break;
            }

            usleep(20_000);
        }

        if ($timedOut) {
            // Po ubiciu drzewa potok oddaje resztę bufora — **jednym przebiegiem selektywnego
            // odczytu**, a nie „do końca strumienia”: gdyby ubijanie zawiodło, czekanie na EOF
            // przywróciłoby dokładnie tę awarię, przed którą stoi limit.
            $output .= $this->drain($pipes);

            foreach ($pipes as $pipe) {
                fclose($pipe);
            }

            // **Świadomie nie wołamy `proc_close`.** Ono czeka, aż potomne potoki zostaną
            // domknięte, a cały sens limitu to **nie czekać**. Uchwyt procesu zwalnia się razem
            // z procesem PHP, który i tak kończy pracę, a kod wyjścia `124` nie pochodzi
            // z procesu, więc nie ma po co po niego sięgać.
            return ['exit_code' => 124, 'output' => trim($output)];
        }

        // Proces skończył się sam, więc jego potoki są domknięte i można spokojnie dobrać resztę.
        $output .= $this->drainRemaining($pipes);

        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        return ['exit_code' => proc_close($process), 'output' => trim($output)];
    }

    /**
     * Ubija **drzewo** procesów hook-a, nie samą powłokę.
     *
     * Na Windows `proc_open` ze stringiem uruchamia `cmd /c …`, a `proc_terminate` kończy
     * wyłącznie `cmd`: dziecko (`prettier`, `php -r "sleep(30);"`, cokolwiek) żyje dalej
     * i **trzyma potoki otwarte**. Zmierzone: test wiszącego hook-a trwał na Windows **30,2 s**
     * przy limicie 1 s, czyli dokładnie tyle, ile spał hook — a obietnica „limit, bo skrypt
     * czekający na wejście wiesza pipeline na zawsze” przestawała być prawdą. Na Linuksie
     * problemu nie ma, bo `sh -c` dla pojedynczego polecenia **zastępuje się** tym poleceniem
     * (zabity shell = zabity hook) — dlatego CI tego nie widziało, a wada wyszła dopiero
     * z uruchomienia bramki na hoście z Windows.
     *
     * `taskkill /T` jest na Windows jedynym sposobem na drzewo i jest częścią systemu, więc nie
     * dokładamy zależności. Jego niepowodzenie nie jest błędem: `proc_terminate` leci tak czy
     * tak, a `run()` po przekroczeniu limitu **nie czeka** na potoki (patrz komentarz przy
     * `proc_close`), więc nawet całkowita porażka ubijania nie wiesza pipeline'u.
     *
     * @param resource $process
     */
    private function killTree($process): void
    {
        // `proc_get_status()` w PHP 8.3+ **zawsze** oddaje `pid` (i to jako `int`), więc nie ma
        // czego bronić `?? null`-em — zabezpieczenie na starsze PHP byłoby tu martwym kodem.
        $pid = proc_get_status($process)['pid'];

        if (PHP_OS_FAMILY === 'Windows' && $pid > 0) {
            $killerPipes = [];
            $killer = proc_open(
                ['taskkill', '/F', '/T', '/PID', (string) $pid],
                [1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']],
                $killerPipes,
            );

            if (is_resource($killer)) {
                proc_close($killer);
            }
        }

        proc_terminate($process, 9);
    }

    /**
     * Bierze to, co **już jest** w potokach — i ani bajtu więcej.
     *
     * Trzy rzeczy, każda z powodem:
     *
     * 1. **`stream_select` przed odczytem.** Na Windows potoki z `proc_open` są zawsze
     *    blokujące (`stream_set_blocking()` nic tam nie zmienia), więc bez pytania „czy są dane”
     *    odczyt czekałby na wyjście hooka.
     * 2. **`fread`, nie `stream_get_contents`.** To drugie czyta **do końca strumienia**, czyli
     *    znowu czeka na koniec procesu — nawet gdy dane już przyszły. Zmierzone: hook, który
     *    coś wypisał i potem spał 30 s, dawał 30 s mimo poprawnie liczonego czasu.
     * 3. **Budżet na jedno wywołanie.** Hook wypisujący dużo (formater na setkach plików) nie
     *    może zostać zablokowany naszym tempem czytania: pętla wraca tu co 20 ms, więc bierzemy
     *    porcję i wracamy do liczenia czasu. Limit dotyczy **czasu**, nie ilości wyjścia.
     *
     * @param array<int, resource> $pipes
     */
    private function drain(array $pipes): string
    {
        $output = '';
        $budget = self::READ_BUDGET;

        foreach ($pipes as $pipe) {
            if (!is_resource($pipe)) {
                continue;
            }

            while ($budget > 0) {
                /** @var list<resource> $read */
                $read = [$pipe];
                $write = null;
                $except = null;

                if (@stream_select($read, $write, $except, 0, 0) !== 1) {
                    break;
                }

                $chunk = fread($pipe, self::READ_CHUNK);
                if (!is_string($chunk) || $chunk === '') {
                    break;
                }

                $output .= $chunk;
                $budget -= strlen($chunk);
            }
        }

        return $output;
    }

    /**
     * Reszta wyjścia **po zakończonym procesie** — wtedy wolno czytać do EOF, bo pisarz już nie
     * istnieje i żaden potok nie zostanie otwarty „na zapas”.
     *
     * @param array<int, resource> $pipes
     */
    private function drainRemaining(array $pipes): string
    {
        $output = '';

        foreach ($pipes as $pipe) {
            if (!is_resource($pipe)) {
                continue;
            }

            while (($chunk = fread($pipe, self::READ_CHUNK)) !== false && $chunk !== '') {
                $output .= $chunk;
            }
        }

        return $output;
    }
}
