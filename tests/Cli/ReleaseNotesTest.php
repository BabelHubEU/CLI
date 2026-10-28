<?php

declare(strict_types=1);

namespace Tests\Cli;

use BabelHub\Cli\Build\ReleaseNotes;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReleaseNotesTest extends TestCase
{
    public function testOnlyTheRequestedReleaseBecomesThePublicNotes(): void
    {
        $markdown = "# Changelog\n\n## [Unreleased]\n- Future change.\n\n"
            . "## [0.1.5] - 2026-10-28\n\n### Fixed\n\n- Repair local catalogs.\n\n"
            . "## [0.1.4] - 2026-09-01\n- Old change.\n";
        self::assertSame("### Fixed\n\n- Repair local catalogs.\n", ReleaseNotes::forVersion($markdown, '0.1.5'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidEntries(): iterable
    {
        yield 'missing version' => ['## [Unreleased]\n- Future change.', '0.1.5'];
        yield 'missing date' => ["## [0.1.5]\n- A change.\n", '0.1.5'];
        yield 'invalid date' => ["## [0.1.5] - 2026-02-30\n- A change.\n", '0.1.5'];
        yield 'empty entry' => ["## [0.1.5] - 2026-10-28\n\n## [0.1.4] - 2026-09-01\n- Old change.\n", '0.1.5'];
        yield 'placeholder' => ["## [0.1.5] - 2026-10-28\n- TBD\n", '0.1.5'];
        yield 'ellipsis placeholder' => ["## [0.1.5] - 2026-10-28\n- ...\n", '0.1.5'];
        yield 'duplicate entry' => [str_repeat("## [0.1.5] - 2026-10-28\n- A change.\n\n", 2), '0.1.5'];
        yield 'invalid version' => ["## [whatever] - 2026-10-28\n- A change.\n", 'whatever'];
    }

    #[DataProvider('invalidEntries')]
    public function testIncompleteOrAmbiguousEntriesBlockPublication(string $markdown, string $version): void
    {
        $this->expectException(InvalidArgumentException::class);
        ReleaseNotes::forVersion($markdown, $version);
    }
}
