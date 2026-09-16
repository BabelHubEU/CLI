<?php

declare(strict_types=1);

namespace Tests\Cli;

use BabelHub\Cli\ExitCode;
use PHPUnit\Framework\TestCase;
use Tests\Cli\Support\CliWorkspace;
use Tests\Cli\Support\FakeApiClient;

/**
 * Próg bramki z repozytorium (`checks.fail_on` w `.babelhub.yml`) — i flaga, która go przebija.
 *
 * Po co to osobny test: próg bramki jest decyzją zespołową, więc musi dać się zapisać
 * w repozytorium i przejść code review. Flaga zostaje na wyjątki („dziś sprawdzam ostrzej”),
 * a **nie** na odwrót — inaczej każdy pipeline musiałby powtarzać ten sam zestaw flag,
 * a nowy fragment CI zapomniałby o jednej.
 *
 * Sprawdzamy **kod wyjścia**, nie to, co CLI wysłało: próg jest lokalną decyzją, a serwis
 * o nim nie wie (dlatego `pass: true` przy ostrzeżeniu zostaje `pass: true` — to CLI decyduje,
 * że przy `checks.fail_on: warning` to nie wystarcza).
 */
final class ValidateThresholdFromConfigTest extends TestCase
{
    private CliWorkspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = new CliWorkspace();
        $this->workspace->writeCredentials();
    }

    protected function tearDown(): void
    {
        $this->workspace->cleanup();

        parent::tearDown();
    }

    public function testTheConfigurationSetsTheDefaultThreshold(): void
    {
        $this->workspace->writeProjectConfigYaml(failOn: 'warning');
        $client = (new FakeApiClient())->script('POST', '/api/v1/projects/learnhub/qa', $this->warningsOnly());

        $result = $this->workspace->run(['command' => 'validate'], $client);

        self::assertSame(
            ExitCode::CheckFailed->value,
            $result['code'],
            'ostrzeżenia przy `checks.fail_on: warning` blokują merge — bez flagi, bo tak mówi plik',
        );
    }

    public function testTheFlagWinsOverTheConfiguration(): void
    {
        $this->workspace->writeProjectConfigYaml(failOn: 'warning');
        $client = (new FakeApiClient())->script('POST', '/api/v1/projects/learnhub/qa', $this->warningsOnly());

        $result = $this->workspace->run(['command' => 'validate', '--fail-on' => 'error'], $client);

        self::assertSame(
            ExitCode::Success->value,
            $result['code'],
            '`--fail-on=error` łagodzi bramkę na jedno uruchomienie, nie zmieniając pliku w repo',
        );
    }

    /** @return array<string, mixed> */
    private function warningsOnly(): array
    {
        return [
            'pass' => true,
            'locale' => null,
            'summary' => ['errors' => 0, 'warnings' => 1, 'info' => 0, 'total' => 1],
            'findings' => [[
                'code' => 'placeholder_extra',
                'severity' => 'warning',
                'key' => 'a.one',
                'locale' => 'pl_PL',
                'message' => 'Nadmiarowy placeholder.',
                'params' => [],
            ]],
            'application' => 'learnhub',
        ];
    }
}
