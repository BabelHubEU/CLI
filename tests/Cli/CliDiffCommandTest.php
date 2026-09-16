<?php

declare(strict_types=1);

namespace Tests\Cli;

use BabelHub\Cli\Config\CatalogLock;
use BabelHub\Cli\ExitCode;
use PHPUnit\Framework\TestCase;
use Tests\Cli\Support\CliWorkspace;
use Tests\Cli\Support\FakeApiClient;

/**
 * `babelhub diff` — co się zmieniło od wskazanej chwili (albo od ostatniego `pull`).
 *
 * Trzy rzeczy są tu kontraktem, nie kosmetyką:
 *
 * * **`--since` bez flagi i bez locka to błąd użycia (`2`)**, a nie „pokaż wszystko”: diff bez
 *   punktu odniesienia wygląda jak „nic się nie zmieniło”, a znaczy „nie wiem od kiedy”;
 * * **kod `0` także wtedy, gdy coś się zmieniło** — to odczyt, bramką jest `validate`;
 * * **`json` to ciało odpowiedzi serwisu dosłownie**, a nie to, co CLI zrozumiało.
 */
final class CliDiffCommandTest extends TestCase
{
    private const SINCE = '2026-09-01T00:00:00Z';
    private const PATH = '/api/v1/projects/learnhub/keys/diff?since=2026-09-01T00%3A00%3A00Z';

    private CliWorkspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = new CliWorkspace();
        $this->workspace->writeProjectConfig();
        $this->workspace->writeCredentials();
    }

    protected function tearDown(): void
    {
        $this->workspace->cleanup();

        parent::tearDown();
    }

    public function testItAsksForTheDiffWithTheGivenDate(): void
    {
        $client = $this->client();

        $result = $this->diff(['--since' => self::SINCE], $client);

        self::assertSame(ExitCode::Success->value, $result['code']);
        self::assertSame(1, $client->callCount('GET', self::PATH), 'data musi jechać jako ISO-8601 ze strefą');
    }

    public function testInJsonModeTheServerBodyGoesToStdoutVerbatim(): void
    {
        $result = $this->diff(['--output' => 'json', '--since' => self::SINCE], $this->client());

        self::assertSame(ExitCode::Success->value, $result['code']);
        self::assertSame(trim($this->body()), trim($result['stdout']), 'dokument serwisu, nie jego interpretacja');
        self::assertSame('', $result['stderr'], 'raport dla człowieka nie ma prawa wejść na stderr w trybie json');
    }

    public function testInPlainModeEveryKeyIsOneGrepableLine(): void
    {
        $result = $this->diff(['--output' => 'plain', '--since' => self::SINCE], $this->client());

        self::assertSame(ExitCode::Success->value, $result['code']);
        self::assertStringContainsString('new a.one', $result['stdout']);
        self::assertStringContainsString('changed b.two', $result['stdout']);
        self::assertStringContainsString('removed c.three', $result['stdout']);
        self::assertStringContainsString('Total:    3', $result['stdout'], 'liczby zostają w trybie plain');
        self::assertStringNotContainsString('<info>', $result['stdout'], 'plain jest do `grep`-a');
        self::assertStringNotContainsString('Nowe klucze:', $result['stdout'], 'nagłówki to ozdoba trybu text');
    }

    public function testInTextModeThePrintoutExplainsWhatEachBucketMeans(): void
    {
        $result = $this->diff(['--since' => self::SINCE], $this->client());

        self::assertStringContainsString('Nowe klucze:', $result['stdout']);
        self::assertStringContainsString('może być nieaktualne', $result['stdout'], 'legenda jest dla człowieka');
        self::assertStringContainsString('new a.one', $result['stdout']);
    }

    public function testWithNoChangesItSaysSoInsteadOfPrintingNothing(): void
    {
        $client = (new FakeApiClient())->script('GET', self::PATH, [
            'application' => 'learnhub',
            'since' => '2026-09-01T00:00:00+00:00',
            'counts' => ['new' => 0, 'changed' => 0, 'removed' => 0, 'total' => 0],
            'truncated' => ['new' => false, 'changed' => false, 'removed' => false],
            'keys' => ['new' => [], 'changed' => [], 'removed' => []],
        ]);

        $result = $this->diff(['--since' => self::SINCE], $client);

        self::assertSame(ExitCode::Success->value, $result['code']);
        self::assertStringContainsString('Nic się nie zmieniło', $result['stdout']);
    }

    public function testWithoutSinceItUsesTheDateOfTheLastPullFromTheLock(): void
    {
        $this->workspace->saveLock(new CatalogLock('learnhub', [
            'en_GB' => ['version' => 2, 'keys' => 91, 'pulled_at' => '2026-09-05T10:00:00+00:00'],
            'pl_PL' => ['version' => 13, 'keys' => 91, 'pulled_at' => '2026-09-05T10:00:00+00:00'],
        ]));

        $client = (new FakeApiClient())->script(
            'GET',
            '/api/v1/projects/learnhub/keys/diff?since=2026-09-05T10%3A00%3A00%2B00%3A00',
            $this->payload(),
        );

        $result = $this->diff([], $client);

        self::assertSame(ExitCode::Success->value, $result['code']);
        self::assertSame(
            1,
            $client->callCount('GET', '/api/v1/projects/learnhub/keys/diff?since=2026-09-05T10%3A00%3A00%2B00%3A00'),
            'bez --since punktem odniesienia jest data ostatniego pull',
        );
    }

    public function testWithoutSinceAndWithoutALockItRefusesToGuess(): void
    {
        $result = $this->diff([], $this->client());

        self::assertSame(ExitCode::Usage->value, $result['code']);
        self::assertStringContainsString('--since', $result['stderr'], 'komunikat ma powiedzieć, czego brakuje');
        self::assertSame('', $result['stdout']);
    }

    public function testATruncatedListIsMarkedInsteadOfSilentlyCut(): void
    {
        $body = $this->payload();
        $body['counts'] = ['new' => 500, 'changed' => 0, 'removed' => 0, 'total' => 500];
        $body['truncated'] = ['new' => true, 'changed' => false, 'removed' => false];

        $client = (new FakeApiClient())->script('GET', self::PATH, $body);
        $result = $this->diff(['--output' => 'plain', '--since' => self::SINCE], $client);

        self::assertStringContainsString('New:      500+', $result['stdout'], '„500” bez plusa byłoby liczbą kłamiącą');
    }

    /** @return iterable<string, array{0: int, 1: ExitCode, 2: string}> */
    public static function failures(): iterable
    {
        yield 'brak zakresu' => [403, ExitCode::Unauthorized, 'Brak uprawnień translations:read.'];
        yield 'limit żądań' => [429, ExitCode::RateLimited, 'Za dużo żądań.'];
        yield 'zła data' => [422, ExitCode::Rejected, 'Parametr "since" jest wymagany'];
        yield 'awaria serwisu' => [500, ExitCode::Failure, 'Nieoczekiwany błąd serwisu.'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failures')]
    public function testApiFailuresMapToTheDocumentedCodes(int $status, ExitCode $expected, string $message): void
    {
        $client = (new FakeApiClient())->script(
            'GET',
            self::PATH,
            ['error' => 'x', 'message' => $message],
            $status,
        );

        $result = $this->diff(['--since' => self::SINCE], $client);

        self::assertSame($expected->value, $result['code']);
        self::assertStringContainsString($message, $result['stderr']);
        self::assertSame('', $result['stdout'], 'niepowodzenie nie wypisuje wyniku');
    }

    private function client(): FakeApiClient
    {
        return (new FakeApiClient())->script('GET', self::PATH, $this->payload());
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'application' => 'learnhub',
            'since' => '2026-09-01T00:00:00+00:00',
            'counts' => ['new' => 1, 'changed' => 1, 'removed' => 1, 'total' => 3],
            'truncated' => ['new' => false, 'changed' => false, 'removed' => false],
            'keys' => [
                'new' => [['key' => 'a.one', 'origin' => 'scan', 'first_seen_at' => '2026-09-02T10:00:00+00:00']],
                'changed' => [['key' => 'b.two', 'origin' => 'scan', 'source_changed_at' => '2026-09-03T10:00:00+00:00']],
                'removed' => [['key' => 'c.three', 'origin' => 'scan', 'removed_at' => '2026-09-04T10:00:00+00:00']],
            ],
        ];
    }

    private function body(): string
    {
        return (string) json_encode($this->payload(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function diff(array $arguments, FakeApiClient $client): array
    {
        return $this->workspace->run(['command' => 'diff'] + $arguments, $client);
    }
}
