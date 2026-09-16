<?php

declare(strict_types=1);

namespace Tests\Cli;

use BabelHub\Cli\Git\DisabledSyncMarker;
use BabelHub\Cli\Git\GitSyncMarker;
use BabelHub\Cli\Git\SyncMarkerInterface;
use PHPUnit\Framework\TestCase;
use Tests\Cli\Support\GitWorkspace;

/**
 * Znacznik synchronizacji w git-cie (1.18) — na **prawdziwym repozytorium**.
 *
 * Trzy reguły, które ten test rozdziela, bo każda ma inny powód:
 *
 * 1. **Brak informacji to „nie wiem”, nie „już wysłane”.** Katalog bez gita, brak gita w PATH,
 *    odłączony HEAD (typowy dla CI przy pull requeście) — wszystko to musi dawać `null`, czyli
 *    normalny push. Odwrotne zachowanie po cichu wstrzymałoby synchronizację.
 * 2. **Zmiana liczy się tylko w plikach, które `push` czyta.** `sync` po `pull` zostawia
 *    zmienione pliki katalogów docelowych i to jest stan poprawny — gdyby znacznik patrzył na
 *    całe drzewo, mechanizm nie zadziałałby **nigdy** po pierwszym pullu.
 * 3. **Jeden znacznik na gałąź, przesuwany.** Repozytorium nie zarasta tagami, a pytanie
 *    „co poszło ostatnio” ma jedną odpowiedź.
 */
final class GitSyncMarkerTest extends TestCase
{
    private const WATCHED = ['src', 'translations/messages.en_GB.php'];

    private string $root;

    private GitSyncMarker $marker;

    protected function setUp(): void
    {
        parent::setUp();

        if (!GitWorkspace::available()) {
            self::markTestSkipped('git nie jest dostępny w tym środowisku — znacznik działa bez niego');
        }

        $this->root = sys_get_temp_dir() . '/babelhub-git-test-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0777, true);
        mkdir($this->root . '/translations', 0777, true);
        file_put_contents($this->root . '/src/app.php', "<?php\n");
        file_put_contents($this->root . '/translations/messages.en_GB.php', "<?php\nreturn [];\n");
        file_put_contents($this->root . '/translations/messages.pl_PL.php', "<?php\nreturn [];\n");

        $this->marker = new GitSyncMarker();
        $this->git()->init();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);

        parent::tearDown();
    }

    public function testADirectoryThatIsNotARepositorySaysIDoNotKnow(): void
    {
        $plain = sys_get_temp_dir() . '/babelhub-plain-' . bin2hex(random_bytes(6));
        mkdir($plain, 0777, true);

        try {
            self::assertNull($this->marker->revision($plain, self::WATCHED));
        } finally {
            @rmdir($plain);
        }
    }

    public function testADetachedHeadSaysIDoNotKnowInsteadOfInventingABranch(): void
    {
        $this->git()->commit('pierwszy');
        $this->git()->detach();

        self::assertNull(
            $this->marker->revision($this->root, self::WATCHED),
            'w CI przy pull requeście nie ma gałęzi, do której można przypiąć znacznik',
        );
    }

    public function testACleanRepositoryReportsItsCommitAndBranch(): void
    {
        $commit = $this->git()->commit('pierwszy');

        $revision = $this->marker->revision($this->root, self::WATCHED);

        self::assertNotNull($revision);
        self::assertSame($commit, $revision->commit);
        self::assertSame('main', $revision->branch);
        self::assertTrue($revision->pathsUnchanged);
        self::assertSame('babelhub/sync/main', $this->marker->markerLabel('main'));
        self::assertSame($commit, $revision->commit);
        self::assertStringContainsString(mb_substr($commit, 0, 7), $revision->label());
    }

    /**
     * Sedno reguły: plik języka **docelowego** zmieniony przez `pull` nie może psuć decyzji,
     * a plik, który `push` wysyła (kod, plik źródłowy) — musi.
     */
    public function testOnlyThePathsPushReadsDecideWhetherSomethingChanged(): void
    {
        $this->git()->commit('pierwszy');

        file_put_contents($this->root . '/translations/messages.pl_PL.php', "<?php\nreturn ['a.b' => 'X'];\n");
        $afterPull = $this->marker->revision($this->root, self::WATCHED);
        self::assertNotNull($afterPull);
        self::assertTrue(
            $afterPull->pathsUnchanged,
            'pull zostawia zmienione pliki docelowe — inaczej sync nigdy nie pominąłby pushu',
        );

        file_put_contents($this->root . '/src/app.php', "<?php\n// zmiana w kodzie\n");
        $afterCodeChange = $this->marker->revision($this->root, self::WATCHED);
        self::assertNotNull($afterCodeChange);
        self::assertFalse($afterCodeChange->pathsUnchanged, 'zmiana w kodzie to nowy stan do wysłania');

        $this->git()->commit('drugi');
        file_put_contents($this->root . '/translations/messages.en_GB.php', "<?php\nreturn ['a.b' => 'Y'];\n");
        $afterSourceChange = $this->marker->revision($this->root, self::WATCHED);
        self::assertNotNull($afterSourceChange);
        self::assertFalse($afterSourceChange->pathsUnchanged, 'plik źródłowy jest tym, co push wysyła');
    }

    public function testTheMarkerIsOneTagPerBranchAndItMoves(): void
    {
        $first = $this->git()->commit('pierwszy');
        self::assertNull($this->marker->synced($this->root, 'main'), 'świeży klon nie ma znacznika');

        $this->marker->remember($this->root, 'main', $first);
        self::assertSame($first, $this->marker->synced($this->root, 'main'));
        self::assertSame(['babelhub/sync/main'], $this->git()->tags());

        file_put_contents($this->root . '/src/app.php', "<?php\n// druga wersja\n");
        $second = $this->git()->commit('drugi');
        $this->marker->remember($this->root, 'main', $second);

        self::assertSame($second, $this->marker->synced($this->root, 'main'));
        self::assertSame(
            ['babelhub/sync/main'],
            $this->git()->tags(),
            'jeden znacznik na gałąź, przesuwany — repo nie może zarastać tagami',
        );
    }

    public function testAnotherBranchHasItsOwnMarker(): void
    {
        $this->git()->commit('pierwszy');
        $this->git()->run(['checkout', '--quiet', '-b', 'feature/cli']);

        $revision = $this->marker->revision($this->root, self::WATCHED);

        self::assertNotNull($revision);
        self::assertSame('feature/cli', $revision->branch);
        self::assertSame('babelhub/sync/feature/cli', $this->marker->markerLabel('feature/cli'));
    }

    public function testTheDisabledMarkerNeverSkipsAndNeverTouchesTheRepository(): void
    {
        $this->git()->commit('pierwszy');
        $disabled = new DisabledSyncMarker();

        self::assertInstanceOf(SyncMarkerInterface::class, $disabled);
        self::assertNull($disabled->revision($this->root, self::WATCHED));
        self::assertNull($disabled->synced($this->root, 'main'));
        $disabled->remember($this->root, 'main', 'cokolwiek');

        self::assertSame([], $this->git()->tags(), '--no-git-tag nie może zostawiać tagów');
    }

    private function git(): GitWorkspace
    {
        return new GitWorkspace($this->root);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $path . '/' . $entry;
            is_dir($full) ? $this->removeDirectory($full) : @unlink($full);
        }

        @rmdir($path);
    }
}
