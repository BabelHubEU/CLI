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
 *    zapisane, ale niesformatowane.
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
                proc_terminate($process, 9);
                break;
            }

            usleep(20_000);
        }

        // Po ubiciu procesu potok oddaje resztę bufora — bez tego komunikat o przekroczeniu
        // limitu gubiłby to, co skrypt zdążył powiedzieć.
        $output .= $this->drain($pipes);

        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        $exitCode = $timedOut ? 124 : proc_close($process);

        return ['exit_code' => $exitCode, 'output' => trim($output)];
    }

    /**
     * @param array<int, resource> $pipes
     */
    private function drain(array $pipes): string
    {
        $output = '';

        foreach ($pipes as $pipe) {
            $chunk = stream_get_contents($pipe);
            if (is_string($chunk)) {
                $output .= $chunk;
            }
        }

        return $output;
    }
}
