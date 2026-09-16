<?php

declare(strict_types=1);

namespace Tests\Cli;

use BabelHub\Cli\ExitCode;
use BabelHub\Cli\Http\ApiResponse;
use BabelHub\Cli\Output\OutputMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Kontrakt kodów wyjścia — **tabela, której CLI faktycznie przestrzega** (pozycja 1.16 planu).
 *
 * Ten test istnieje, bo w tej kategorii nikt go nie ma: `crowdin download` potrafi wyjść kodem
 * `0` mimo błędu („the job appears to have passed”), a skrypt CI, który dostał zero, nie ma
 * już jak się o tym dowiedzieć. Sprawdzamy więc dwie rzeczy naraz: **żaden błąd nie daje zera**
 * i **różne przyczyny dają różne kody**, bo od kodu zależy reakcja pipeline'u (poczekaj,
 * popraw poświadczenie, popraw dane, popraw tłumaczenia).
 */
final class ExitCodeTest extends TestCase
{
    /** @return iterable<string, array{0: int, 1: ExitCode, 2: string}> */
    public static function responses(): iterable
    {
        yield 'sukces' => [200, ExitCode::Success, 'zwykła odpowiedź'];
        yield 'utworzone' => [201, ExitCode::Success, 'utworzenie zasobu to też sukces'];
        yield 'brak zmian (304)' => [304, ExitCode::Success, '„nic nowego” nie jest błędem'];
        yield 'brak poświadczenia' => [401, ExitCode::Unauthorized, 'napraw poświadczenie'];
        yield 'brak zakresu' => [403, ExitCode::Unauthorized, 'ten sam kod, bo reakcja ta sama: uprawnienia'];
        yield 'nie ma projektu' => [404, ExitCode::Rejected, 'popraw dane wywołania'];
        yield 'niepoprawne dane' => [422, ExitCode::Rejected, 'popraw dane wywołania'];
        yield 'limit żądań' => [429, ExitCode::RateLimited, 'jedyny błąd, który mija sam — stąd osobny kod'];
        yield 'błąd serwisu' => [500, ExitCode::Failure, 'nie nasza wina, ale nie sukces'];
        yield 'brama' => [502, ExitCode::Failure, 'proxy też nie może udawać sukcesu'];
    }

    #[DataProvider('responses')]
    public function testHttpStatusMapsToTheDocumentedExitCode(int $status, ExitCode $expected, string $why): void
    {
        self::assertSame($expected, (new ApiResponse($status, '{}'))->exitCode(), $why);
    }

    public function testOnlySuccessIsZero(): void
    {
        foreach (ExitCode::cases() as $code) {
            if ($code === ExitCode::Success) {
                self::assertTrue($code->isSuccess());
                continue;
            }

            self::assertFalse($code->isSuccess(), sprintf('kod %d (%s) nie może znaczyć „zrobione”', $code->value, $code->name));
            self::assertNotSame(0, $code->value, 'każdy kod poza Success musi być niezerowy — to cała bramka CI');
        }
    }

    public function testTheMessageComesFromTheApiBodyAndNotFromTheStatusCode(): void
    {
        $response = new ApiResponse(422, '{"error":"validation","message":"Pole \\"key\\" jest wymagane."}');

        self::assertSame('validation', $response->errorCode());
        self::assertSame('Pole "key" jest wymagane.', $response->message(), 'człowiek ma przeczytać zdanie serwisu');
    }

    public function testAnHtmlResponseDoesNotPretendToBeJson(): void
    {
        $response = new ApiResponse(502, '<html>Bad gateway</html>');

        self::assertSame([], $response->json());
        self::assertSame('HTTP 502', $response->message(), 'brak ciała JSON to nie powód, żeby zmyślać treść');
    }

    public function testRetryAfterIsReadFromTheHeaders(): void
    {
        $response = new ApiResponse(429, '{"error":"rate_limited"}', ['Retry-After' => ['42']]);

        self::assertSame(42, $response->retryAfter());
        self::assertNull((new ApiResponse(429, '{}'))->retryAfter(), 'brak nagłówka to brak informacji, a nie zero');
    }

    public function testOutputModeIsAClosedList(): void
    {
        self::assertSame(OutputMode::Json, OutputMode::tryParse('JSON'));
        self::assertNull(OutputMode::tryParse('xml'), 'nieznany tryb to błąd użycia, nie cichy powrót do text');
    }
}
