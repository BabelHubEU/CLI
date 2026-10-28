<?php

declare(strict_types=1);

namespace BabelHub\Cli\Build;

use InvalidArgumentException;

/** The versioned changelog is the single source for published release notes. */
final class ReleaseNotes
{
    public static function forVersion(string $markdown, string $version): string
    {
        if (preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z]+(?:[.-][0-9A-Za-z]+)*)?$/D', $version) !== 1) {
            throw new InvalidArgumentException('Expected a release version such as 0.1.5.');
        }
        $heading = '^## \[' . preg_quote($version, '/') . '\] - (\d{4})-(\d{2})-(\d{2})[ \t]*$';
        if (preg_match_all('/' . $heading . '\R(.*?)(?=^## |\z)/ms', $markdown, $matches, PREG_SET_ORDER) !== 1) {
            throw new InvalidArgumentException('CHANGELOG.md must have exactly one dated entry for ' . $version . '.');
        }
        [$entry, $year, $month, $day, $body] = $matches[0];
        if (!checkdate((int) $month, (int) $day, (int) $year)) {
            throw new InvalidArgumentException('The release changelog entry must have a valid YYYY-MM-DD date.');
        }
        $body = trim($body);
        if (preg_match('/^-[ \t]+\S.+$/m', $body) !== 1 || preg_match('/^-[ \t]*(?:(?:TODO|TBD)\b|\.{3}(?:\s|$))/im', $body) === 1) {
            throw new InvalidArgumentException('The release changelog entry must contain changes, not placeholders.');
        }
        return $body . "\n";
    }
}
