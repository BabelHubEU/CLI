<?php

declare(strict_types=1);

namespace Tests\Cli;

use BabelHub\Cli\Config\CatalogLock;
use BabelHub\Cli\ExitCode;
use PHPUnit\Framework\TestCase;
use Tests\Cli\Support\CliWorkspace;
use Tests\Cli\Support\FakeApiClient;
use Tests\Cli\Support\RecordedRequests;

/**
 * `babelhub sync` — cała pętla jednym poleceniem, w kolejności, która ma znaczenie.
 *
 * Dwie reguły są tu sprawdzane **na zapisanych żądaniach**, a nie na komunikatach, bo tylko one
 * są dowodem:
 *
 * 1. **`push` przed `pull`.** `pull` pobiera wyłącznie teksty zatwierdzone, więc nowy klucz
 *    dodany w kodzie i tak nie wróci — a gdyby `pull` szedł pierwszy, deweloper patrzyłby na stan
 *    sprzed własnej zmiany i musiałby się domyślać, czy push w ogóle zadziałał.
 * 2. **Pierwszy niezerowy kod przerywa polecenie.** `push`, który zwrócił `6` (serwis pominął
 *    część kluczy), nie jest przepuszczany dalej: `pull` na niekompletnym stanie daje pliki,
 *    których nikt nie zweryfikował. Test sprawdza to wprost — brak żądania `GET` po nieudanym
 *    pushu — bo sam komunikat „pomijam pull” mógłby kłamać.
 */
final class SyncCommandTest extends TestCase
{
    private const SYNC_PATH = '/api/v1/projects/learnhub/sync';
    private const PROJECT_PATH = '/api/v1/projects/learnhub';
    private const CATALOG_PATH = self::PROJECT_PATH . '/translations/pl_PL';
    private const CATALOG_FILE = 'translations/messages.pl_PL.php';

    private CliWorkspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = new CliWorkspace();
        $this->workspace->writeProjectConfig();
        $this->workspace->writeCredentials();
        // Klucze w kodzie i teksty źródłowe: `push` ma co wysłać, więc `sync` dochodzi do `pull`.
        $this->workspace->write('src/Keys.php', implode("\n", [
            '<?php',
            '',
            "trans('a.one');",
            "trans('b.two');",
        ]) . "\n");
        $this->workspace->write('translations/messages.en_GB.php', implode("\n", [
            '<?php',
            '',
            'return [',
            "    'a.one' => 'One',",
            "    'b.two' => 'Two',",
            '];',
            '',
        ]));
    }

    protected function tearDown(): void
    {
        $this->workspace->cleanup();

        parent::tearDown();
    }

    public function testPushGoesBeforePullAndTheRecordedPathsProveIt(): void
    {
        $client = $this->scriptSync($this->client());
        $this->scriptProject($client);
        $this->scriptCatalog($client, 4, ['a.one' => 'Jeden']);

        $result = $this->workspace->run(['command' => 'sync'], $client);

        self::assertSame(ExitCode::Success->value, $result['code']);
        self::assertSame(
            [self::SYNC_PATH, self::PROJECT_PATH, '/api/v1/me', self::CATALOG_PATH],
            (new RecordedRequests($client))->paths(),
            'najpierw stan kodu do serwisu, potem gotowe tłumaczenia z powrotem — odwrotna kolejność pokazuje deweloperowi stan sprzed jego zmiany',
        );
        self::assertSame(['POST', 'GET', 'GET', 'GET'], (new RecordedRequests($client))->methods());

        self::assertStringContainsString('nowe 2', $result['stdout'], 'wynik pushu jest częścią wyniku sync');
        self::assertStringContainsString('zapisane (1 kluczy, wersja 4)', $result['stdout'], 'wynik pullu też');
        self::assertTrue($this->workspace->exists(self::CATALOG_FILE), 'sync domyka pętlę: katalog wraca do repozytorium');

        $pushMark = strpos($result['stderr'], '— push —');
        $pullMark = strpos($result['stderr'], '— pull —');
        self::assertIsInt($pushMark);
        self::assertIsInt($pullMark);
        self::assertLessThan($pullMark, $pushMark, 'postęp pokazuje człowiekowi tę samą kolejność, którą widzi serwis');
    }

    public function testASkippedKeyStopsTheCommandBeforePull(): void
    {
        $client = $this->scriptSync($this->client(), ['skipped' => ['Zły Klucz' => 'Nieprawidłowy format klucza.']]);

        $result = $this->workspace->run(['command' => 'sync'], $client);

        self::assertSame(
            ExitCode::CheckFailed->value,
            $result['code'],
            'kod wyjścia jest kodem kroku, który poległ — zero znaczyłoby „cała pętla się udała”',
        );
        self::assertSame(
            [self::SYNC_PATH],
            (new RecordedRequests($client))->paths(),
            'pull po niekompletnym pushu dałby pliki, których nikt nie zweryfikował',
        );
        self::assertStringNotContainsString('— pull —', $result['stderr']);
        self::assertStringContainsString('Serwis pominął 1 pozycji', $result['stderr']);
        self::assertFileDoesNotExist($this->workspace->path(self::CATALOG_FILE));
        self::assertFileDoesNotExist($this->workspace->path(CatalogLock::FILE_NAME), 'przerwana pętla nie zostawia locka opisującego stan, który nie powstał');
    }

    public function testADryRunSendsNeitherThePushNorTheCatalogRequest(): void
    {
        $client = $this->client();

        $result = $this->workspace->run(['command' => 'sync', '--dry-run' => true], $client);

        self::assertSame(ExitCode::Success->value, $result['code']);
        self::assertSame(
            0,
            (new RecordedRequests($client))->count(),
            '--dry-run istnieje po to, żeby nie zmienić niczego — ani w serwisie, ani w plikach',
        );
        self::assertStringContainsString('[dry-run] Wysłałbym 2 kluczy do projektu learnhub', $result['stdout']);
        self::assertStringContainsString('Pomijam pull', $result['stderr']);
        self::assertFileDoesNotExist($this->workspace->path(self::CATALOG_FILE));
        self::assertFileDoesNotExist($this->workspace->path(CatalogLock::FILE_NAME));
    }

    public function testAFailedPullReturnsTheCodeOfTheStepThatFailed(): void
    {
        $client = $this->scriptSync($this->client());
        $client->script('GET', self::PROJECT_PATH, [
            'error' => 'forbidden',
            'message' => 'Brak uprawnień translations:read.',
        ], 403);

        $result = $this->workspace->run(['command' => 'sync'], $client);

        self::assertSame(ExitCode::Unauthorized->value, $result['code'], 'krok `pull` poległ na poświadczeniu, więc kod mówi „napraw poświadczenie”');
        self::assertStringContainsString('Brak uprawnień translations:read.', $result['stderr']);
        self::assertSame(2, (new RecordedRequests($client))->count(), 'push zdążył wysłać stan kodu — to nie jest błąd, dopiero pull nie doszedł do skutku');
    }

    private function client(): FakeApiClient
    {
        return (new FakeApiClient(CliWorkspace::API_URL, CliWorkspace::TOKEN))
            ->script('GET', '/api/v1/me', ['organization' => ['slug' => 'acme']]);
    }

    /** @param array<string, mixed> $overrides */
    private function scriptSync(FakeApiClient $client, array $overrides = []): FakeApiClient
    {
        return $client->script('POST', self::SYNC_PATH, $overrides + [
            'application' => 'learnhub',
            'created' => 2,
            'updated' => 0,
            'removed' => 0,
            'unchanged' => 0,
            'source_changed' => 2,
            'skipped' => [],
        ]);
    }

    private function scriptProject(FakeApiClient $client): FakeApiClient
    {
        return $client->script('GET', self::PROJECT_PATH, [
            'project' => [
                'slug' => 'learnhub',
                'name' => 'QA demo',
                'source_locale' => 'en_GB',
                'locales' => ['pl_PL'],
                'is_active' => true,
            ],
        ]);
    }

    /** @param array<string, string> $messages */
    private function scriptCatalog(FakeApiClient $client, int $version, array $messages): FakeApiClient
    {
        return $client->script('GET', self::CATALOG_PATH, [
            'application' => 'learnhub',
            'locale' => 'pl_PL',
            'version' => $version,
            'translations' => $messages,
        ]);
    }
}
