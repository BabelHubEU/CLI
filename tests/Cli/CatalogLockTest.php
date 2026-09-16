<?php

declare(strict_types=1);

namespace Tests\Cli;

use BabelHub\Cli\Config\CatalogLock;
use BabelHub\Cli\Config\CatalogLockStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Cli\Support\CliWorkspace;

/**
 * `.babelhub.lock.json` — **które wersje katalogów są w tym commicie**.
 *
 * Lock jest jedynym plikiem, który odpowiada na pytanie „czy umiesz dać mi tłumaczenia dla
 * konkretnego commita?” bez pytania serwera, a `pull` opiera na nim całą oszczędność diffu
 * (`If-None-Match` wersją z locka). Dlatego sprawdzamy tu trzy rzeczy, na których to stoi:
 * **round-trip bez zgubienia danych**, **odrzucenie uszkodzonego pliku** (zamiast zgadywania
 * jego kształtu) oraz **zapis atomowy** (żadnego `.tmp` w repozytorium, bo plik tymczasowy
 * w commicie to dokładnie ten rodzaj śmiecia, który zostaje na zawsze).
 *
 * `withLocale()` jest `readonly`-owo niemutujący nie z upodobania do funkcyjności: `--check`
 * porównuje „lock przed” z „lockiem po”, więc nadpisanie w miejscu zniszczyłoby dowód rozjazdu,
 * który ma zostać pokazany człowiekowi.
 */
final class CatalogLockTest extends TestCase
{
    private CliWorkspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = new CliWorkspace();
    }

    protected function tearDown(): void
    {
        $this->workspace->cleanup();

        parent::tearDown();
    }

    public function testTheLockSurvivesARoundTripThroughTheStore(): void
    {
        $lock = new CatalogLock('learnhub', [
            'en_GB' => ['version' => 2, 'keys' => 91, 'pulled_at' => '2026-09-14T09:13:27+00:00'],
            'pl_PL' => ['version' => 13, 'keys' => 91, 'pulled_at' => '2026-09-14T09:13:27+00:00'],
        ]);

        $store = $this->store();
        $store->save($lock);

        self::assertSame($this->workspace->path(CatalogLock::FILE_NAME), $store->path(), 'lock leży w katalogu projektu');

        $loaded = $store->load();
        self::assertNotNull($loaded);
        self::assertSame('learnhub', $loaded->project);
        self::assertSame(['en_GB', 'pl_PL'], $loaded->localeCodes(), 'kolejność języków zostaje — plik ma być czytelny w diffie');
        self::assertSame(2, $loaded->versionFor('en_GB'));
        self::assertSame(13, $loaded->versionFor('pl_PL'));
        self::assertSame(91, $loaded->keysFor('pl_PL'), 'liczba kluczy opisuje katalog, nie sam lock');
        self::assertSame('2026-09-14T09:13:27+00:00', $loaded->locales['pl_PL']['pulled_at'] ?? null);

        $onDisk = $this->decode($this->workspace->read(CatalogLock::FILE_NAME));
        self::assertSame('learnhub', $onDisk['project'] ?? null, 'plik na dysku ma być czytelnym JSON-em, a nie serializacją obiektu');
    }

    /** @return iterable<string, array{0: string|null}> */
    public static function unreadableFiles(): iterable
    {
        yield 'brak pliku' => [null];
        yield 'plik pusty' => [''];
        yield 'same spacje' => ["  \n\t\n"];
        yield 'nie JSON' => ['{to nie jest json'];
        yield 'JSON, ale nie obiekt' => ['"learnhub"'];
        yield 'tablica bez projektu' => ['{"locales": {"pl_PL": {"version": 1}}}'];
    }

    #[DataProvider('unreadableFiles')]
    public function testAnUnreadableLockFileIsTreatedAsNoLockAtAll(?string $contents): void
    {
        if ($contents !== null) {
            $this->workspace->write(CatalogLock::FILE_NAME, $contents);
        }

        self::assertNull(
            $this->store()->load(),
            'uszkodzony lock to brak locka — pull ma wtedy po prostu zapytać bez If-None-Match, a nie przewrócić się',
        );
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function malformedPayloads(): iterable
    {
        yield 'pusty obiekt' => [[]];
        yield 'brak projektu' => [['locales' => []]];
        yield 'pusty projekt' => [['project' => '', 'locales' => []]];
        yield 'projekt nie jest napisem' => [['project' => 42, 'locales' => []]];
        yield 'brak języków' => [['project' => 'learnhub']];
        yield 'języki nie są tablicą' => [['project' => 'learnhub', 'locales' => 'pl_PL']];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('malformedPayloads')]
    public function testAMalformedPayloadIsRejectedInsteadOfGuessed(array $payload): void
    {
        self::assertNull(
            CatalogLock::fromArray($payload),
            'lock bez projektu albo bez mapy języków nie opisuje żadnego stanu repozytorium',
        );
    }

    public function testLocaleEntriesWithWrongTypesAreNormalizedInsteadOfDiscardingTheWholeFile(): void
    {
        $lock = CatalogLock::fromArray([
            'project' => 'learnhub',
            'locales' => [
                'pl_PL' => ['version' => '13', 'keys' => null, 'pulled_at' => 42],
                'de_DE' => ['version' => 4, 'keys' => 7, 'pulled_at' => '2026-09-14T09:13:27+00:00'],
                'uszkodzony' => 'to nie jest wpis',
            ],
        ]);

        self::assertNotNull($lock, 'jeden zepsuty wpis nie może unieważnić całego locka — wersje pozostałych języków są nadal prawdziwe');
        self::assertSame(4, $lock->versionFor('de_DE'));
        self::assertSame(7, $lock->keysFor('de_DE'));
        self::assertSame(0, $lock->versionFor('pl_PL'), 'wersja niecałkowita to zero, a nie zmyślona liczba');
        self::assertSame(0, $lock->keysFor('pl_PL'));
        self::assertSame('', $lock->locales['pl_PL']['pulled_at'] ?? null);
        self::assertNull($lock->versionFor('uszkodzony'), 'wpis, który nie jest mapą, nie udaje języka');
    }

    public function testWithLocaleReturnsANewLockAndLeavesTheOriginalAlone(): void
    {
        $original = new CatalogLock('learnhub', [
            'en_GB' => ['version' => 2, 'keys' => 91, 'pulled_at' => '2026-09-14T09:13:27+00:00'],
        ]);

        $updated = $original->withLocale('pl_PL', 13, 91, '2026-09-15T10:00:00+00:00');

        self::assertNotSame($original, $updated, 'readonly: nowa wartość, nie zmiana w miejscu');
        self::assertNull($original->versionFor('pl_PL'), 'lock „przed pull” musi zostać nietknięty — to on jest dowodem rozjazdu');
        self::assertSame(13, $updated->versionFor('pl_PL'));
        self::assertSame(91, $updated->keysFor('pl_PL'));
        self::assertSame(2, $updated->versionFor('en_GB'), 'dopisanie języka nie gubi pozostałych');
        self::assertSame('learnhub', $updated->project);

        $twice = $updated->withLocale('de_DE', 1, 4, '2026-09-15T11:00:00+00:00');

        self::assertSame(['en_GB', 'pl_PL', 'de_DE'], $twice->localeCodes());
        self::assertNull($original->versionFor('de_DE'), 'łańcuch zmian nie dotyka oryginału');
    }

    public function testKeysForAndVersionForAMissingLocaleAreNull(): void
    {
        $lock = new CatalogLock('learnhub', []);

        self::assertNull($lock->keysFor('pl_PL'), 'brak języka to brak informacji, a nie zero kluczy');
        self::assertNull($lock->versionFor('pl_PL'), 'brak wersji znaczy „zapytaj bez If-None-Match”');
        self::assertSame([], $lock->localeCodes());
    }

    public function testSaveLeavesNoTemporaryFileBehind(): void
    {
        $store = $this->store();

        $store->save(new CatalogLock('learnhub', [
            'pl_PL' => ['version' => 13, 'keys' => 91, 'pulled_at' => '2026-09-14T09:13:27+00:00'],
        ]));

        self::assertSame(
            [CatalogLock::FILE_NAME],
            $this->workspace->entries(),
            'plik tymczasowy w repozytorium zostałby w commicie na zawsze — zapis musi kończyć się rename',
        );

        // Powtórny zapis podmienia plik, a nie dopisuje obok: to jest cała różnica między
        // „lock opisuje stan” a „lock opisuje historię, której nikt nie czyta”.
        $store->save(new CatalogLock('learnhub', [
            'pl_PL' => ['version' => 14, 'keys' => 92, 'pulled_at' => '2026-09-15T10:00:00+00:00'],
        ]));

        self::assertSame([CatalogLock::FILE_NAME], $this->workspace->entries());
        $reloaded = $store->load();
        self::assertNotNull($reloaded);
        self::assertSame(14, $reloaded->versionFor('pl_PL'));
        self::assertSame(92, $reloaded->keysFor('pl_PL'));
        self::assertStringEndsWith(
            "\n",
            $this->workspace->read(CatalogLock::FILE_NAME),
            'plik kończy się nową linią — inaczej git pokazuje „no newline at end of file”',
        );
    }

    private function store(): CatalogLockStore
    {
        return new CatalogLockStore($this->workspace->root());
    }

    /** @return array<mixed> */
    private function decode(string $raw): array
    {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            self::fail('Oczekiwano obiektu JSON w pliku locka.');
        }

        return $decoded;
    }
}
