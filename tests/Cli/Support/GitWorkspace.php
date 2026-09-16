<?php

declare(strict_types=1);

namespace Tests\Cli\Support;

use RuntimeException;

/**
 * Prawdziwe repozytorium git w katalogu tymczasowym — do testów znacznika synchronizacji (1.18).
 *
 * **Nie atrapa.** Znacznik jest zbudowany na trzech poleceniach git-a (`rev-parse`, `status`,
 * `tag --force`), a każda atrapa git-a sprawdzałaby tylko moje wyobrażenie o ich zachowaniu:
 * czy `status --porcelain` milczy przy czystym drzewie, czy `tag --force` przesuwa istniejący
 * znacznik i jak wygląda odłączony HEAD. To ten sam powód, dla którego test formatu pliku
 * językowego pisze prawdziwy plik (`LocaleFileTest`).
 *
 * Wymaga `git` w PATH; brak gita **pomija** testy (`markTestSkipped`), a nie przewraca ich —
 * narzędzie działa bez gita, więc jego brak nie może być awarią zestawu testów.
 */
final readonly class GitWorkspace
{
    public function __construct(private string $root)
    {
    }

    public static function available(): bool
    {
        return self::git(dirname(__DIR__, 3), ['--version']) !== null;
    }

    public function init(): void
    {
        $this->run(['init', '--initial-branch=main']);
        // Tożsamość lokalna: kontener CI bywa bez `user.name`, a `git commit` wtedy odmawia.
        $this->run(['config', 'user.email', 'tests@babelhub.local']);
        $this->run(['config', 'user.name', 'BabelHub tests']);
        $this->run(['config', 'commit.gpgsign', 'false']);
    }

    /** Dodaje wszystko i commituje; zwraca skrót commita. */
    public function commit(string $message): string
    {
        $this->run(['add', '--all']);
        $this->run(['commit', '--message', $message, '--quiet']);

        return $this->revParse('HEAD') ?? throw new RuntimeException('commit nie powstał');
    }

    public function revParse(string $ref): ?string
    {
        return self::git($this->root, ['rev-parse', '--verify', '--quiet', $ref]);
    }

    /** @return list<string> */
    public function tags(): array
    {
        $output = self::git($this->root, ['tag', '--list']);

        return $output === null || $output === '' ? [] : explode("\n", $output);
    }

    /** Odłącza HEAD od gałęzi — stan typowy dla CI przy pull requeście. */
    public function detach(): void
    {
        $this->run(['checkout', '--detach', '--quiet']);
    }

    /** @param list<string> $arguments */
    public function run(array $arguments): void
    {
        if (self::git($this->root, $arguments) === null) {
            throw new RuntimeException('git ' . implode(' ', $arguments) . ' nie powiódł się');
        }
    }

    /**
     * @param list<string> $arguments
     *
     * @return string|null wyjście (przycięte) albo `null` przy niezerowym kodzie
     */
    private static function git(string $directory, array $arguments): ?string
    {
        if (!function_exists('proc_open')) {
            return null;
        }

        $process = proc_open(
            ['git', '-C', $directory, ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $directory,
        );

        if (!is_resource($process)) {
            return null;
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process) === 0 ? trim($stdout) : null;
    }
}
