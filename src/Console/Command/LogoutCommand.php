<?php

declare(strict_types=1);

namespace BabelHub\Cli\Console\Command;

use BabelHub\Cli\Config\CredentialsStore;
use BabelHub\Cli\Config\PendingPairingStore;
use BabelHub\Cli\Console\Application;
use BabelHub\Cli\ExitCode;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `babelhub logout` — kasuje **zapisane poświadczenie**, a nie „unieważnia token”.
 *
 * Rozróżnienie jest istotne i musi być widoczne w komunikacie: token nadal istnieje po stronie
 * serwisu i nadal działa dla każdego, kto go ma (na przykład w zmiennej CI). Odwołanie tokenu
 * to osobna, świadoma czynność w panelu — i CLI o tym mówi, zamiast sugerować, że wylogowanie
 * zamknęło dostęp.
 *
 * Wylogowanie jest **idempotentne**: brak poświadczenia to nie błąd, a `0`. Skrypt, który
 * sprząta po sobie, nie powinien się przewracać na drugim uruchomieniu.
 */
#[AsCommand(name: 'logout', description: 'Usuwa zapisane poświadczenie z tej maszyny (tokenu nie odwołuje)')]
final class LogoutCommand extends Command
{
    public function __construct(private readonly CredentialsStore $store)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $printer = Application::printer($input, $output);
        $profileOption = $input->getOption('profile');
        $profile = is_string($profileOption) && $profileOption !== '' ? $profileOption : null;

        // Zaczęte parowanie też kasujemy: zostawiony plik stanu trzymałby sekret terminala
        // bez powodu, a jego obecność myliłaby następne `login --complete`.
        PendingPairingStore::alongsideCredentials($this->store->path())->forget();

        // Ostrzeżenie pada **także wtedy, gdy nie było pliku**: to najczęstsze nieporozumienie
        // wokół `logout` w narzędziach CLI — człowiek „wylogowuje” maszynę, a polecenia dalej
        // używają tokenu ze zmiennej środowiskowej.
        $envToken = $_ENV[CredentialsStore::ENV_TOKEN] ?? getenv(CredentialsStore::ENV_TOKEN);
        $environmentToken = is_string($envToken) && $envToken !== '';

        $removed = $this->store->forget($profile);

        // Wynik maszynowo, bo `logout` jest **idempotentny**: `removed: false` znaczy „nie było
        // czego kasować”, a to jest odpowiedź, po którą skrypt tu przyszedł (1.17). Wcześniej
        // w trybie `json` na `stdout` nie było niczego i tej różnicy nie dało się odczytać.
        $printer->json([
            'logged_out' => true,
            'removed' => $removed,
            'profile' => $profile ?? CredentialsStore::DEFAULT_PROFILE,
            'credentials_path' => $this->store->path(),
            'environment_token_set' => $environmentToken,
        ]);

        if ($removed) {
            $printer->info(sprintf('Poświadczenie usunięte z %s.', $this->store->path()));
        } else {
            $printer->info('Nie było czego kasować — na tej maszynie nie ma zapisanego poświadczenia.');
        }

        if ($environmentToken) {
            $printer->warn(sprintf(
                'Zmienna %s jest nadal ustawiona w tym środowisku — polecenia będą dalej używać tokenu z niej.',
                CredentialsStore::ENV_TOKEN,
            ));
        }

        if ($removed) {
            $printer->info('Token nadal istnieje po stronie serwisu — odwołaj go w panelu (Ustawienia → Tokeny osobiste), jeśli wyciekł.');
        }

        return ExitCode::Success->value;
    }
}
