<?php

declare(strict_types=1);

namespace Tests\Cli;

use BabelHub\Cli\Config\CatalogLock;
use BabelHub\Cli\Config\CatalogSource;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CatalogSourceTest extends TestCase
{
    #[DataProvider('canonicalUrls')]
    public function testEquivalentServerUrlsHaveTheSameIdentity(string $url, string $expected): void
    {
        $source = new CatalogSource($url, 'acme');
        self::assertSame($expected, $source->apiUrl);
        self::assertTrue($source->equals(new CatalogSource($expected, 'acme')));
    }

    /** @return iterable<string, array{string, string}> */
    public static function canonicalUrls(): iterable
    {
        yield 'HTTPS host and default port' => ['HTTPS://BabelHub.Example:443/', 'https://babelhub.example'];
        yield 'HTTP default port' => ['http://BabelHub.Example:80/', 'http://babelhub.example'];
        yield 'base path retained' => ['https://babelhub.example/service///', 'https://babelhub.example/service'];
        yield 'non-default port retained' => ['https://babelhub.example:8443/', 'https://babelhub.example:8443'];
        yield 'IPv6' => ['http://[::1]:80/', 'http://[::1]'];
    }

    #[DataProvider('unsafeUrls')]
    public function testSourceIdentityCannotPersistUrlCredentialsOrTokens(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CatalogSource($url, 'acme');
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeUrls(): iterable
    {
        foreach (
            ['https://user:secret@example.test', 'https://secret@example.test', 'https://example.test?token=secret',
            'https://example.test#secret', 'file:///tmp/server', 'not-a-url'] as $url
        ) {
            yield $url => [$url];
        }
    }

    public function testLockIdentitySurvivesSerializationAndLocaleUpdates(): void
    {
        $source = new CatalogSource('https://BabelHub.Example:443/', 'acme');
        $lock = (new CatalogLock('learnhub', [], $source))->withLocale('pl_PL', 7, 2, 'sentinel');
        $loaded = CatalogLock::fromArray($lock->toArray());
        self::assertNotNull($loaded);
        self::assertTrue($loaded->belongsTo('learnhub', $source));
        self::assertFalse($loaded->belongsTo('other', $source));
        self::assertFalse($loaded->belongsTo('learnhub', new CatalogSource('https://babelhub.example', 'other')));
        self::assertFalse($loaded->belongsTo('learnhub', new CatalogSource('http://babelhub.example', 'acme')));
        self::assertFalse($loaded->belongsTo('learnhub', new CatalogSource('https://babelhub.example/api', 'acme')));
        self::assertSame(7, $loaded->versionFor('pl_PL'));
        self::assertNull($loaded->versionFor('en_GB'));
        self::assertSame($lock->toArray(), $loaded->toArray());
    }

    public function testLegacyAndMalformedIdentitiesCannotValidateTheCurrentSource(): void
    {
        foreach ([null, [], ['api_url' => 'https://example.test?token=secret', 'organization' => 'acme']] as $source) {
            $lock = CatalogLock::fromArray(['project' => 'learnhub', 'locales' => [], 'source' => $source]);
            self::assertNotNull($lock);
            self::assertFalse($lock->belongsTo('learnhub', new CatalogSource('https://example.test', 'acme')));
        }
    }
}
