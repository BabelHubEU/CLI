<?php

declare(strict_types=1);

namespace BabelHub\Cli\Git;

/**
 * `git` jako źródło znacznika synchronizacji (1.18).
 *
 * **Wersja z `--no-git-tag`.** Zachowuje ten sam kontrakt co prawdziwy znacznik, ale nic nie wie
 * o repozytorium: nie pomija pushu i nie stawia tagu. To **jawna decyzja wywołującego**, nie cichy
 * brak — dlatego jest osobną klasą, a nie `null`-em przekazywanym do `CliPush`.
 */
final readonly class DisabledSyncMarker implements SyncMarkerInterface
{
    public function revision(string $root, array $paths): ?CodeRevision
    {
        return null;
    }

    public function synced(string $root, string $branch): ?string
    {
        return null;
    }

    public function remember(string $root, string $branch, string $commit): void
    {
        // Nic: użytkownik powiedział „nie dotykaj git-a w moim repozytorium”.
    }

    public function markerLabel(string $branch): string
    {
        // Nie ma znacznika, więc nie ma czego nazywać — a ta gałąź nigdy nie jest osiągalna,
        // bo `revision()` zwraca `null` i `CliPush` nie pyta o znacznik.
        return '';
    }

    public function publish(string $root, string $branch): bool
    {
        return false;
    }
}
