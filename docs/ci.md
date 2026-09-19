# BabelHub w CI klienta (GitHub Actions i GitLab CI)

Ten dokument jest dla **aplikacji korzystającej** z BabelHub. Gotowe pliki do skopiowania leżą
w [`examples/ci/`](../examples/ci/):

| Platforma | Plik | Gdzie trafia |
| --- | --- | --- |
| GitHub Actions | `examples/ci/github-actions.yml` | `<twoje-repo>/.github/workflows/babelhub.yml` |
| GitLab CI | `examples/ci/gitlab-ci.yml` | `<twoje-repo>/.gitlab-ci.yml` |

CI **tego repozytorium** (testy, PHPStan, PHPCS, binarka PHAR, wydanie) to inny plik:
`.gitlab-ci.yml`. Nie mieszaj ich — fragmenty tutaj **nie uruchamiają** BabelHub, tylko
rozmawiają z nim po HTTP.

## Co fragmenty robią i dlaczego tak

```
pull request / merge request                gałąź główna
──────────────────────────────              ──────────────────────────────
babelhub status --output=plain              babelhub sync --output=json
babelhub push --dry-run --output=json         ├─ push: klucze z kodu → serwis
babelhub validate --check=hardcoded-strings   └─ pull: tłumaczenia → pliki
babelhub validate --check=coverage          git commit + push (jeśli były zmiany)
babelhub validate --check=quality
babelhub validate --check=acceptance
   └─ kod 6 = NIE MERGUJ
```

**Dlaczego cztery kroki, a nie jeden `babelhub validate`.** Bo GitHub i GitLab pokazują w pull
requeście **nazwę tego kroku, który padł** — `BabelHub / coverage` na czerwono mówi, co blokuje
merge, bez otwierania logu. Jeden krok z zestawem z `checks.run` (`.babelhub.yml`) robi to samo
taniej (jedno żądanie na endpoint zamiast dwóch, bo `quality` i `coverage` czytają ten sam raport
QA), ale w checkach widać wtedy tylko „validate”. W obu wariantach developer dostaje nazwę
kontroli w treści komunikatu na `stderr`.

| Krok | Co sprawdza | Kiedy blokuje |
| --- | --- | --- |
| `hardcoded-strings` | tekst wpisany wprost w kodzie (`WARNING`) | nigdy domyślnie; `--fail-on=warning` |
| `coverage` | klucz bez zatwierdzonego tłumaczenia (`ERROR`) | zawsze (kod `6`) |
| `quality` | placeholdery, HTML, ICU, duplikaty, puste | `ERROR` zawsze, `WARNING` przy `--fail-on=warning` |
| `acceptance` | tłumaczenia czekające na zatwierdzenie (`WARNING`) | tylko przy `--fail-on=warning` |

Kontrola `hardcoded-strings` stoi **pierwsza** i jest jedyną, która nie potrzebuje tokenu ani
serwera — najtańsza porażka to porażka bez żadnego żądania. `acceptance` jest w przykładzie
raportem, nie bramką: niezatwierdzona wartość to zwykle praca w toku, a nie defekt, a fałszywy
alarm blokujący merge uczy ludzi obchodzenia bramki. Zespół, który chce twardej bramki („na main
nie wchodzi nic niezatwierdzonego”), zmienia tam próg na `--fail-on=warning` — świadomie, bo
wtedy blokują też ostrzeżenia z `quality` i `hardcoded-strings`.

**Dlaczego bramką jest `validate`, a nie `push`.** `push` opisuje stan kodu (i w trybie
`--dry-run` nic nie wysyła), a `validate` rozstrzyga. Bramka na `push` blokowałaby merge za to,
że ktoś właśnie dodał klucz — czyli za rzecz, którą ten przepływ ma ułatwiać.

**Dlaczego `push --dry-run` w ogóle jest w PR-ze.** Bo odpowiada na pytanie, które pojawia się
przy każdym nowym kluczu: „czy skan w ogóle go widzi?”. Skaner PHP/Twig pomija klucze dynamiczne
(`t('a.' ~ $x)`) i o tym trzeba wiedzieć **przed** scaleniem, a nie po.

**Dlaczego `sync` tylko na gałęzi głównej.** Bo `pull` pisze pliki i robi commit zwrotny;
na PR-ze z forka byłoby to pisanie do cudzego repozytorium (a sekretów i tak tam nie ma — dlatego
job `quality` ma warunek `head.repo.full_name == github.repository`).

**Dlaczego lock jedzie w tym samym commicie co pliki.** `.babelhub.lock.json` mówi, które wersje
katalogów są w tym commicie. Bez niego następny `pull` nie ma z czym porównać `If-None-Match`
i pobiera wszystko od nowa (a przy okazji nadpisuje pliki, nawet gdy nic się nie zmieniło).

**Formatowanie plików zostaje w repozytorium, nie w CI.** Jeśli projekt ma formatter dla plików
tłumaczeń (`prettier`, `php-cs-fixer`, własny skrypt), zapisz go w `.babelhub.yml` jako
`download.post_process_command` — `sync` uruchomi go po zapisaniu plików, a więc **przed**
commitem zwrotnym. Dzięki temu w pipeline nie ma drugiego kroku, o którym można zapomnieć,
a commit zawiera plik już sformatowany (niepowodzenie hook-a kończy `sync` kodem `1`, więc
niesformatowany plik nie pojedzie po cichu).

## Artefakt dla tłumacza (`GET …/export/{locale}.xliff`)

Bramka odpowiada na pytanie „czy wolno mergować”. Drugie pytanie brzmi „jak przekazać teksty
tłumaczowi, który nie ma konta w BabelHub” — i na to odpowiada eksport, bez żadnego CLI:

```bash
curl -sS -H "Authorization: Bearer $BABELHUB_TOKEN" \
     -H 'If-None-Match: "13"' \
     -o translations.xliff -w '%{http_code}\n' \
     "$BABELHUB_API_URL/api/v1/projects/$PROJECT/export/pl_PL.xliff"
```

* `-OJ` zamiast `-o` zapisze plik pod nazwą z nagłówka (`learnhub.pl_PL.xliff`);
* `If-None-Match` z wersją z manifestu daje `304`, więc **nie generuj artefaktu, który się nie
  zmienił** — w GitHub Actions wystarczy `if: steps.manifest.outputs.version != …`;
* `?version=N` to kontrola, nie wybór wersji: gdy serwis ma inną, odpowiedź to `409`
  (`version_mismatch`) — lepiej dowiedzieć się tego w CI niż z artefaktu, który nie odpowiada
  sprawdzonemu stanowi;
* ten sam adres z `.json` daje **dokładnie ten sam dokument, co katalog** — czyli plik, który
  można podać z powrotem importowi (`translation:import <plik>` albo `POST …/translations/{locale}`).

Zakres potrzebny do eksportu to `translations:read` — ten sam, który ma już `status` i `pull`.

## Poświadczenie

W obu platformach jedna zmienna: **`BABELHUB_TOKEN`** — dokładnie ta, którą czyta CLI
(kolejność źródeł: flaga → zmienna → plik poświadczeń, `docs/cli.md`).

| Platforma | Gdzie ustawić | Uwagi |
| --- | --- | --- |
| GitHub Actions | Settings → Secrets and variables → Actions → `BABELHUB_TOKEN` | sekrety nie trafiają do PR-ów z forków |
| GitLab CI | Settings → CI/CD → Variables → `BABELHUB_TOKEN` (masked, protected) | bez „masked” token widać w logu joba |

Do odesłania commita potrzebny jest jeszcze token **repozytorium** (GitHub: `GITHUB_TOKEN`
z `permissions: contents: write`; GitLab: `I18N_BOT_TOKEN` z zakresem `write_repository`, bo
`CI_JOB_TOKEN` nie może wypychać commitów). To dwa różne sekrety i nie mieszaj ich: pierwszy
należy do BabelHub, drugi do GitLaba/GitHuba.

Token BabelHub tworzysz w panelu (**Ustawienia → Tokeny osobiste**) albo przez parowanie
(`babelhub login` → zatwierdzenie kodu w `/cli/authorize`). Potrzebne zakresy:

* `translations:read` — `status`, `pull`, `validate --check=acceptance`;
* `translations:write` — `push`, `sync`;
* `qa:read` — `validate --check=quality` i `validate --check=coverage`.

Token **nigdy** nie trafia do `.babelhub.json` ani do `.gitlab-ci.yml`: plik konfiguracji
w repozytorium nie zawiera sekretu, i to jest niezmiennik projektu, a nie dobra rada.
Zmienna **nie** może być też ustawiona lokalnie, jeśli nie chcesz, żeby `pull` w terminalu
używał tokenu CI — kolejność źródeł jest jawna: `BABELHUB_TOKEN` wygrywa z plikiem.

## Instalacja CLI w CI

Pakiet jest w Packagu (`babelhub/cli`), więc fragmenty instalują go jedną linią — bez konfiguracji
repozytorium VCS i bez żadnego sekretu do czytania źródeł:

```bash
composer global require babelhub/cli:~0.1.3 --no-interaction
export PATH="$PATH:$(composer global config bin-dir --absolute)"
```

**Constraint piszemy jako `~0.1.3`, nigdy `^0.1`** — i to nie jest kwestia gustu. Composer zawęża
caret na wersji `0.x` do wersji dokładnej: `require babelhub/cli:^0.1` zapisuje w `composer.json`
`0.1` (czyli „dokładnie 0.1.0”) i **instaluje `0.1.0`**, omijając późniejsze wydania z tej samej
linii — a `0.1.0` miało defekt limitu hook-a na Windows. Zmierzone **dwa razy**: raz z repozytorium
VCS, raz z Packagista (zachowanie należy do Composera, nie do źródła pakietu): `^0.1` → `0.1.0`,
`~0.1.0` → `0.1.1`. Fragment z podmienionym constraintem przechodzi więc bramkę, którą chcieliśmy
mieć, i to jest dokładnie ten rodzaj cichej różnicy, którego nie widać w logu.

Dół zakresu to **`0.1.3`**, bo to pierwsze wydanie, które rozumie `files.root_key` — deklarację,
że komunikaty mieszkają w kopercie (`{"locale": …, "translations": {…}}`). Klient z takim plikiem
na starszym CLI nie zobaczy błędu: `push` wyśle klucze `translations.…`, a `pull` dołoży drugą
kopię katalogu obok starej (szczegóły i liczby: `docs/cli.md`, sekcja o `files.root_key`).

Jeśli wolisz przypiąć wersję w `composer.json` projektu zamiast instalacji globalnej, użyj
`composer require --dev babelhub/cli:~0.1.3` i wołaj `vendor/bin/babelhub` — reszta fragmentu się
nie zmienia. Binarka bez Composera jest w wydaniach GitLaba jako asset `babelhub.phar`.

## Kody wyjścia w CI

Pipeline reaguje **wyłącznie** na kod wyjścia, więc tabela z `docs/cli.md` jest tu instrukcją
obsługi:

| Kod | Co robi pipeline |
| --- | --- |
| `0` | idzie dalej |
| `1` | awaria (sieć, dysk, `5xx`) — pokaż log, nie zmieniaj danych |
| `2` | błąd użycia/konfiguracji — popraw `.babelhub.json` albo wywołanie |
| `3` | poświadczenie: odśwież `BABELHUB_TOKEN` (albo zakresy) |
| `4` | limiter: poczekaj `Retry-After` i powtórz — **nie** zmieniaj niczego w kodzie |
| `5` | odrzucone dane: projekt nie istnieje, niepoprawny ładunek |
| `6` | kontrole coś znalazły — **bramka** |
| `7` | parowanie czeka na człowieka (w CI nie występuje: CI używa `BABELHUB_TOKEN`) |

Dwie rzeczy, których w fragmentach **celowo nie ma**: `|| true` i `continue-on-error`
(w GitLabie `allow_failure`). To trzy najczęstsze sposoby na bramkę, która nie blokuje — a wtedy
`validate` w CI jest teatrem. Pilnuje tego test `tests/Contract/CiFragmentTest.php`.

## Gdy fragment przestanie działać

Test `tests/Contract/CiFragmentTest.php` w repozytorium BabelHub czyta oba pliki i sprawdza, czy:
każde polecenie i każda flaga istnieją w prawdziwym CLI (z `list --format=json` i `--help`),
każda **nazwana kontrola** jest wołana po nazwie (`--check=…` z listy `CheckName`) i tak samo
nazwana w kroku/jobie, każda wartość z zamkniętej listy (`--output`, `--fail-on`, `--check`)
należy do tej listy, bramka nie jest wyciszona, w pliku nie ma wartości tokenu, obie platformy
wołają ten sam zestaw poleceń, a `docs/ci.md` (ten dokument) nazywa oba fragmenty. Dzięki temu
fragment nie może „przestać działać po cichu”: zmiana nazwy flagi albo kontroli w CLI przewraca
test w tym repozytorium, a nie pipeline u klienta.

Czego ten test **nie** sprawdza (i co trzeba sprawdzić ręcznie przy pierwszym wdrożeniu):
składni specyficznej dla platformy (`rules`, `permissions`, konteksty `github.*`), dostępności
sekretów i uprawnień bota do wypychania commitów. Dlatego pierwszy przebieg rób na gałęzi
testowej, z `workflow_dispatch` (GitHub) albo ręcznym pipeline'em (GitLab).

## Bramki CI w repozytorium CLI (dla porównania)

Ten dokument opisuje CI **klienta**; repozytorium CLI ma własną bramkę w `.gitlab-ci.yml`:
`composer check` (PHPUnit, PHPStan poziom 8, PHPCS) plus zbudowanie binarki PHAR i uruchomienie
jej z katalogu poza repozytorium. Fragmenty z tego dokumentu pilnuje
`tests/Contract/CiFragmentTest.php`, który zestawia je z **prawdziwym** CLI: czy każde polecenie
i każda flaga istnieją, czy bramka nie jest wyciszona i czy token nie trafił do pliku.
