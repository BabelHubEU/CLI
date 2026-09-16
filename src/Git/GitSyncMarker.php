<?php

declare(strict_types=1);

namespace BabelHub\Cli\Git;

/**
 * Znacznik synchronizacji trzymany w **tagach git-a**: `babelhub/sync/<gałąź>`.
 *
 * Cztery decyzje:
 *
 * 1. **Tag, nie plik i nie tag zdalny.** Tag jest w repozytorium lokalnym, więc nie wymaga
 *    uprawnień do zapisu w zdalnym repo, nie wraca jako konflikt w pull requeście (jak plik
 *    commitowany) i nie znika w świeżym klonie (jak plik ignorowany). `git tag` pokazuje stan
 *    bez zaglądania do `.git`.
 * 2. **Tag lekki, nie opisowy.** Tag opisowy (`-a`) wymaga `user.name`/`user.email`, których
 *    w kontenerze CI często nie ma — a wtedy `babelhub push` przewracałby się na konfiguracji
 *    git-a, czyli na rzeczy, która z tłumaczeniami nie ma nic wspólnego. Znacznik to metadane
 *    maszyny, nie notatka wydania.
 * 3. **`--force`, bo znacznik jest ruchomy.** Jeden tag na gałąź, przesuwany po każdym udanym
 *    pushu — inaczej repozytorium zarasta tagami (`babelhub/sync/main-1`, `-2`, …), a pytanie
 *    „co wysłaliśmy ostatnio” wymagałoby sortowania po dacie.
 * 4. **Bez powłoki.** Argumenty idą do `proc_open` jako **lista**, więc nazwa gałęzi albo ścieżka
 *    z apostrofem, spacją czy `;` nie może zmienić uruchamianej komendy. To ta sama zasada, co
 *    „nic z danych nie trafia do komendy hook-a” (1.19) — tylko z drugiej strony: tutaj danych
 *    do komendy nie wpuszczamy wcale.
 */
final readonly class GitSyncMarker implements SyncMarkerInterface
{
    /** Prefiks znaczników; gałąź dopisujemy zamiast tworzyć osobny tag na commit. */
    public const TAG_PREFIX = 'babelhub/sync/';

    public function revision(string $root, array $paths): ?CodeRevision
    {
        $commit = $this->git($root, ['rev-parse', '--verify', '--quiet', 'HEAD^{commit}']);
        if ($commit === null) {
            return null;
        }

        // Odłączony HEAD (`HEAD`) to typowy stan CI dla pull requestów: nie ma gałęzi, do której
        // można przypiąć znacznik, więc mówimy „nie wiem”, a push idzie normalnie.
        $branch = $this->git($root, ['rev-parse', '--abbrev-ref', 'HEAD']);
        if ($branch === null || $branch === 'HEAD') {
            return null;
        }

        return new CodeRevision(
            commit: $commit,
            branch: $branch,
            pathsUnchanged: $this->pathsUnchanged($root, $paths),
        );
    }

    public function synced(string $root, string $branch): ?string
    {
        return $this->git($root, ['rev-parse', '--verify', '--quiet', 'refs/tags/' . self::tag($branch)]);
    }

    public function remember(string $root, string $branch, string $commit): void
    {
        $this->git($root, ['tag', '--force', self::tag($branch), $commit]);
    }

    public function markerLabel(string $branch): string
    {
        return self::tag($branch);
    }

    public function publish(string $root, string $branch): bool
    {
        // `--force`, bo znacznik jest ruchomy: po nowym commicie ten sam tag wskazuje gdzie indziej.
        $result = $this->git($root, ['push', '--force', 'origin', 'refs/tags/' . self::tag($branch)]);

        return $result !== null;
    }

    /** Nazwa znacznika dla gałęzi — jedno miejsce, w którym powstaje. */
    public static function tag(string $branch): string
    {
        return self::TAG_PREFIX . $branch;
    }

    /**
     * Czy pliki, które czyta `push`, są identyczne z tym, co jest w commicie.
     *
     * Pytamy **tylko o te ścieżki**, a nie o całe drzewo: `sync` po `pull` zostawia zmienione
     * pliki katalogów docelowych i to jest stan poprawny — całe drzewo byłoby „brudne” po każdym
     * przebiegu, więc mechanizm nigdy by nie zadziałał.
     *
     * @param list<string> $paths
     */
    private function pathsUnchanged(string $root, array $paths): bool
    {
        if ($paths === []) {
            return false;
        }

        $arguments = ['status', '--porcelain', '--untracked-files=all', '--', ...$paths];
        $status = $this->git($root, $arguments);

        // Niepowodzenie `git status` (np. ścieżka poza repozytorium) traktujemy jak „nie wiem”,
        // czyli „wyślij”: pominięcie pushu na podstawie błędu git-a byłoby cichą stratą danych.
        return $status === '';
    }

    /**
     * @param list<string> $arguments
     *
     * @return string|null wyjście (przycięte) albo `null`, gdy git zawiódł / go nie ma
     */
    private function git(string $root, array $arguments): ?string
    {
        if (!function_exists('proc_open')) {
            return null;
        }

        $command = ['git', '-C', $root, ...$arguments];
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes, $root);
        if (!is_resource($process)) {
            return null;
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        // Czytamy też `stderr`: proces piszący dużo na wyjście błędów zablokowałby się na pipe,
        // zanim doczytalibyśmy `stdout` — a wtedy `babelhub push` wisiałby w CI.
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return $exitCode === 0 ? trim($stdout) : null;
    }
}
