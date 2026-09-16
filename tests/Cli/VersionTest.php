<?php

declare(strict_types=1);

namespace Tests\Cli;

use BabelHub\Cli\Version;
use PHPUnit\Framework\TestCase;

/**
 * Wersja CLI kontra tag wydania — jedyne miejsce, w którym „`--version` mówi prawdę” przestaje
 * być obietnicą, a staje się sprawdzeniem.
 *
 * **Po co ten test.** Plan (1.16) wskazał kłamliwe `--version` jako jedną z rzeczy, w których ta
 * kategoria narzędzi jest sprawdzalnie gorsza od nas („u konkurencji `--version` kłamie i psuje
 * skrypty”), a `src/Version.php` obiecywał w komentarzu test porównujący stałą z manifestem —
 * takiego testu **nie było**. Manifest nie ma pola `version` (Composer i Packagist wyliczają
 * wersję **z tagu**), więc punktem odniesienia jest tag w Git, a nie plik:
 *
 * 1. **HEAD z tagiem `vX.Y.Z`** → stała musi być równa `X.Y.Z`. Inaczej wydajemy binarkę, która
 *    kłamie o sobie, a pipeline warunkujący kroki od `--version` podejmuje decyzje na podstawie
 *    fikcji.
 * 2. **HEAD bez tagu** → stała musi nieść sufiks przedwydaniowy (`-dev`). Praca nad wersją
 *    `0.2.0` na gałęzi jest w porządku; **udawanie, że jest się wydaniem** — nie.
 * 3. **Brak gita albo brak repozytorium** → nie ma czego sprawdzać i test to mówi wprost
 *    (`markTestSkipped`), zamiast przechodzić „na zielono”. W wydaniu ten test **nie może się
 *    pominąć**: workflow wydania uruchamia całą bramkę na commitcie z tagiem, więc stan (1)
 *    jest tam zawsze osiągalny.
 */
final class VersionTest extends TestCase
{
    public function testTheVersionLooksLikeAReleaseNumber(): void
    {
        self::assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+(-[a-z0-9][a-z0-9.]*)?$/',
            Version::VALUE,
            'wersja musi być liczbą wydania, opcjonalnie z sufiksem przedwydaniowym',
        );
    }

    /**
     * Strażnik sprawdzony **na atrapie repozytorium** — bez tego test byłby zielony także wtedy,
     * gdyby porównanie nie działało wcale (np. `git describe` zwracałoby `null` przy każdym tagu,
     * więc „brak tagu” wyglądałoby jak „zgadza się”). Ten projekt wymaga próby negatywnej na
     * każdym strażniku, który czyta cudzy stan.
     */
    public function testTheComparisonDetectsATagThatDoesNotMatch(): void
    {
        $directory = $this->temporaryDirectory();

        try {
            self::assertTrue(
                $this->taggedRepository($directory, 'v1.2.3'),
                'nie udało się przygotować atrapy repozytorium (git bez tożsamości?)',
            );

            self::assertNull(
                $this->mismatch('1.2.3', $directory),
                'zgodny tag nie może być zgłoszony jako rozjazd',
            );
            self::assertSame(
                'v1.2.3',
                $this->mismatch('9.9.9', $directory),
                'tag niezgodny z wersją musi być widoczny — inaczej strażnik nic nie pilnuje',
            );
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function testTheVersionAgreesWithTheTagOfThisCommit(): void
    {
        $directory = dirname(__DIR__, 2);
        $tag = $this->exactTag($directory);

        if ($tag === null) {
            self::markTestSkipped(
                'HEAD nie jest otagowany (albo nie ma gita) — sprawdzenie należy do wydania, '
                . 'które uruchamia tę bramkę na commitcie z tagiem',
            );
        }

        $expected = ltrim($tag, 'v');

        if (str_contains($expected, '-')) {
            self::markTestSkipped(sprintf('tag %s sam jest przedwydaniowy — nie ma czego porównywać', $tag));
        }

        self::assertSame(
            $expected,
            Version::VALUE,
            sprintf('tag %s mówi jedno, a `Version::VALUE` drugie — wydana binarka kłamałaby', $tag),
        );
    }

    /** HEAD bez tagu nie może podawać się za wydanie (patrz punkt 2 w opisie klasy). */
    public function testAnUntaggedCommitDoesNotClaimARelease(): void
    {
        $directory = dirname(__DIR__, 2);

        if ($this->exactTag($directory) !== null) {
            self::markTestSkipped('ten commit jest otagowany — sprawdza to test zgodności z tagiem');
        }

        if (!$this->isRepository($directory)) {
            self::markTestSkipped('brak gita albo to nie repozytorium — nie ma czego sprawdzać');
        }

        self::assertStringContainsString(
            '-',
            Version::VALUE,
            'commit bez tagu musi mieć wersję przedwydaniową (np. `0.2.0-dev`), a nie numer wydania',
        );
    }

    /** Tag wskazujący dokładnie na HEAD albo `null`, gdy go nie ma (albo nie ma gita). */
    private function mismatch(string $version, string $directory): ?string
    {
        $tag = $this->exactTag($directory);

        if ($tag === null) {
            return null;
        }

        return ltrim($tag, 'v') === $version ? null : $tag;
    }

    private function exactTag(string $directory): ?string
    {
        $result = $this->git(['describe', '--tags', '--exact-match', 'HEAD'], $directory);

        if ($result['code'] !== 0) {
            return null;
        }

        $tag = trim($result['stdout']);

        return $tag === '' ? null : $tag;
    }

    private function isRepository(string $directory): bool
    {
        return $this->git(['rev-parse', '--git-dir'], $directory)['code'] === 0;
    }

    /** Atrapa: puste repozytorium z jednym commitem i tagiem na nim. */
    private function taggedRepository(string $directory, string $tag): bool
    {
        if (!$this->isRepository($directory)) {
            $this->git(['init', '--quiet'], $directory);
        }

        $identity = [
            '-c', 'user.email=test@example.com',
            '-c', 'user.name=BabelHub CLI tests',
        ];

        $commit = $this->git([...$identity, 'commit', '--allow-empty', '--quiet', '-m', 'test'], $directory);
        if ($commit['code'] !== 0) {
            return false;
        }

        return $this->git(['tag', $tag], $directory)['code'] === 0;
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{code: int, stdout: string}
     */
    private function git(array $arguments, string $directory): array
    {
        if (!function_exists('proc_open')) {
            return ['code' => 127, 'stdout' => ''];
        }

        $process = proc_open(
            ['git', ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $directory,
        );

        if (!is_resource($process)) {
            return ['code' => 127, 'stdout' => ''];
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => proc_close($process), 'stdout' => $stdout];
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/babelhub-version-test-' . bin2hex(random_bytes(6));
        mkdir($directory, 0777, true);

        return $directory;
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
