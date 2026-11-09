<?php

declare(strict_types=1);

namespace Tests\Cli;

use BabelHub\Cli\Config\ProjectConfig;
use BabelHub\Cli\Config\ProjectConfigStore;
use BabelHub\Cli\ExitCode;
use BabelHub\Cli\Console\Application;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Cli\Support\FakeApiClient;

/**
 * `babelhub content:push` i `content:pull` — treści z repozytorium iz powrotem.
 *
 * Format jest częścią kontraktu: katalog `<key>/` z `meta.json`, `source.html` i `<locale>.html`.
 * Testy sprawdzają **payload**, który dostała atrapa, i **pliki**, które powstały — nie komunikaty.
 * Round-trip (`push` → `pull` do pustego katalogu) jest tu najważniejszy: pokazuje, że format
 * jest stratny tylko tam, gdzie chcemy (status wariantu wraca jako `draft`, bo pliki go nie niosą).
 */
final class ContentCommandsTest extends TestCase
{
    private const API_URL = 'https://babelhub.example';
    private const PUSH_PATH = '/api/v1/projects/learnhub/content';

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/babelhub-content-test-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
        // Konfiguracja przez `ProjectConfigStore`, a nie ręczny JSON: schemat `.babelhub.json`
        // odrzuca nieznane pola, więc test ma przechodzić przez ten sam walidator co `init`.
        (new ProjectConfigStore($this->root))->save(new ProjectConfig(
            project: 'learnhub',
            sourceLocale: 'en_GB',
            filePattern: 'translations/messages.{lang}.php',
            fileFormat: ProjectConfig::FORMAT_PHP,
            scanPaths: ['src'],
        ), $this->root);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);

        parent::tearDown();
    }

    public function testPushSendsOneRequestPerItem(): void
    {
        $this->writeItem('alpha-email', 'email', ['name'], 'Cześć {{name}}!', ['pl_PL' => 'Witaj {{name}}!']);
        $this->writeItem('beta-banner', 'marketing', [], 'Rabat 20%', []);

        $client = $this->client();
        $client->script('POST', self::PUSH_PATH, ['key' => 'alpha-email']);
        $client->script('POST', self::PUSH_PATH, ['key' => 'beta-banner']);

        $result = $this->runCommand('content:push', ['--directory' => $this->root], $client);

        self::assertSame(ExitCode::Success->value, $result['code'], $result['stderr']);
        self::assertSame(2, $client->callCount('POST', self::PUSH_PATH), 'Jedno żądanie na treść — częściowy sukces jest możliwy.');

        // Kolejność jest alfabetyczna po kluczu, więc test czyta pierwsze żądanie, nie ostatnie.
        $sent = array_values(array_filter(
            $client->requests,
            static fn (array $request): bool => $request['method'] === 'POST' && $request['path'] === self::PUSH_PATH,
        ));
        self::assertSame('alpha-email', $sent[0]['payload']['key']);
        self::assertSame('beta-banner', $sent[1]['payload']['key']);
    }

    public function testPushSendsTheSourceAndVariantsVerbatim(): void
    {
        $this->writeItem('welcome-email', 'email', ['name', 'order'], '<p>Cześć {{name}}!</p>', [
            'pl_PL' => '<p>Witaj {{name}}!</p>',
            'de_DE' => '<p>Hallo {{name}}!</p>',
        ]);

        $client = $this->client();
        $client->script('POST', self::PUSH_PATH, ['key' => 'welcome-email']);

        $this->runCommand('content:push', ['--directory' => $this->root], $client);

        $request = $client->lastRequest('POST', self::PUSH_PATH);
        self::assertNotNull($request);
        self::assertSame('<p>Cześć {{name}}!</p>', $request['payload']['source'], 'Treść jedzie dosłownie — bez wykonywania szablonu.');
        self::assertSame(['name', 'order'], $request['payload']['variables']);
        self::assertSame('<p>Witaj {{name}}!</p>', $request['payload']['variants']['pl_PL']['body']);
        self::assertSame('<p>Hallo {{name}}!</p>', $request['payload']['variants']['de_DE']['body']);
    }

    public function testADryRunSendsNothing(): void
    {
        $this->writeItem('welcome-email', 'email', [], 'Cześć', []);

        $client = $this->client();
        $result = $this->runCommand('content:push', ['--directory' => $this->root, '--dry-run' => true], $client);

        self::assertSame(ExitCode::Success->value, $result['code']);
        self::assertSame([], $client->requests, '--dry-run nie może wysłać niczego.');
        self::assertStringContainsString('[dry-run] welcome-email (email)', $result['stdout']);
    }

    public function testAPartialFailureIsReportedWithAFailureCode(): void
    {
        // Klucze tak dobrane, żeby kolejność alfabetyczna była jawna: dobra treść idzie pierwsza.
        $this->writeItem('alpha-good', 'email', [], 'Ok', []);
        $this->writeItem('beta-bad', 'email', [], 'Cześć {{name}}', []);

        $client = $this->client();
        $client->script('POST', self::PUSH_PATH, ['key' => 'alpha-good']);
        $client->scriptRaw('POST', self::PUSH_PATH, 422, json_encode(['error' => 'validation', 'message' => 'Nieznana zmienna: name'], JSON_THROW_ON_ERROR));

        $result = $this->runCommand('content:push', ['--directory' => $this->root], $client);

        self::assertSame(ExitCode::Failure->value, $result['code']);
        self::assertStringContainsString('Nieznana zmienna: name', $result['stderr'], 'Powód odmowy musi dojść do człowieka.');
        self::assertStringContainsString('alpha-good (email) — zapisane', $result['stdout'], 'Jedna zła treść nie cofa dobrych.');
    }

    public function testAMissingDirectoryIsAUsageError(): void
    {
        $client = $this->client();

        $result = $this->runCommand('content:push', ['--directory' => $this->root], $client);

        self::assertSame(ExitCode::Usage->value, $result['code']);
        self::assertStringContainsString('content', $result['stderr']);
    }

    public function testPullWritesTheDirectoryLayout(): void
    {
        $client = $this->client();
        $client->script('GET', self::PUSH_PATH, ['project' => 'learnhub', 'items' => [
            ['key' => 'welcome-email', 'type' => 'email'],
        ]]);
        $client->script('GET', self::PUSH_PATH . '/welcome-email', [
            'key' => 'welcome-email',
            'type' => 'email',
            'source' => '<p>Cześć {{name}}!</p>',
            'variables' => ['name'],
            'variants' => ['pl_PL' => ['body' => '<p>Witaj {{name}}!</p>', 'status' => 'approved']],
        ]);

        $result = $this->runCommand('content:pull', ['--directory' => $this->root], $client);

        self::assertSame(ExitCode::Success->value, $result['code'], $result['stderr']);
        self::assertSame('<p>Cześć {{name}}!</p>', file_get_contents($this->root . '/content/welcome-email/source.html'));
        self::assertSame('<p>Witaj {{name}}!</p>', file_get_contents($this->root . '/content/welcome-email/pl_PL.html'));

        $meta = json_decode((string) file_get_contents($this->root . '/content/welcome-email/meta.json'), true);
        self::assertSame('email', $meta['type']);
        self::assertSame(['name'], $meta['variables']);
    }

    public function testPullingIntoAFreshDirectoryThenPushingSendsTheSameContent(): void
    {
        // Round-trip: format zapisany przez `pull` musi dać ten sam payload w `push`.
        $client = $this->client();
        $client->script('GET', self::PUSH_PATH, ['items' => [['key' => 'welcome-email']]]);
        $client->script('GET', self::PUSH_PATH . '/welcome-email', [
            'key' => 'welcome-email',
            'type' => 'push',
            'source' => 'Cześć {{name}}',
            'variables' => ['name'],
            'variants' => ['pl_PL' => ['body' => 'Witaj {{name}}']],
        ]);
        $this->runCommand('content:pull', ['--directory' => $this->root], $client);

        $push = $this->client();
        $push->script('POST', self::PUSH_PATH, ['key' => 'welcome-email']);
        $this->runCommand('content:push', ['--directory' => $this->root], $push);

        $request = $push->lastRequest('POST', self::PUSH_PATH);
        self::assertNotNull($request);
        self::assertSame('push', $request['payload']['type']);
        self::assertSame('Cześć {{name}}', $request['payload']['source']);
        self::assertSame('Witaj {{name}}', $request['payload']['variants']['pl_PL']['body']);
    }

    public function testAnItemWithoutMetaIsARefusedInsteadOfSilentlySkipped(): void
    {
        mkdir($this->root . '/content/broken', 0777, true);
        file_put_contents($this->root . '/content/broken/source.html', 'Cześć');

        $client = $this->client();

        $result = $this->runCommand('content:push', ['--directory' => $this->root], $client);

        self::assertSame(ExitCode::Failure->value, $result['code']);
        self::assertStringContainsString('meta.json', $result['stderr']);
        self::assertSame([], $client->requests, 'Nieczytelny wpis wstrzymuje wysyłkę — nie zgadujemy jego typu.');
    }

    private function client(): FakeApiClient
    {
        return new FakeApiClient(self::API_URL, 'pat_test.sekret');
    }

    /**
     * @param list<string> $variables
     * @param array<string, string> $variants
     */
    private function writeItem(string $key, string $type, array $variables, string $source, array $variants): void
    {
        $directory = $this->root . '/content/' . $key;
        mkdir($directory, 0777, true);
        file_put_contents($directory . '/meta.json', json_encode(
            ['type' => $type, 'variables' => $variables],
            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
        ));
        file_put_contents($directory . '/source.html', $source);
        foreach ($variants as $locale => $body) {
            file_put_contents($directory . '/' . $locale . '.html', $body);
        }
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function runCommand(string $command, array $arguments, FakeApiClient $client): array
    {
        $stdout = new BufferedOutput();
        $stderr = new BufferedOutput();

        $code = Application::run(
            credentialsPath: $this->root . '/credentials.json',
            stdout: $stdout,
            stderr: $stderr,
            input: new ArrayInput([
                'command' => $command,
                '--api-url' => self::API_URL,
                '--token' => 'pat_test.sekret',
            ] + $arguments),
            clientFactory: static fn (string $url, ?string $token): FakeApiClient => $client,
        );

        return ['code' => $code, 'stdout' => $stdout->fetch(), 'stderr' => $stderr->fetch()];
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
