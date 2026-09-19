# BabelHub CLI (`babelhub`)

CLI jest **kanałem akwizycji, nie funkcją premium** (§26.1 planu): instaluje się je jednym
poleceniem, działa bez konta w panelu, a monetyzacja dzieje się po stronie hostowanego serwisu.
Dlatego `babelhub` jest osobnym pakietem (`cli/`, manifest `cli/composer.json`), a nie konsolą
serwisu: nie ciągnie Doctrine, nie zna bazy i nie wymaga `composer install` w repozytorium klienta.

```bash
composer global require babelhub/cli     # po wydaniu pakietu (Fala 1, pozycja 1.11)
babelhub login                           # parowanie w przeglądarce
babelhub init                            # projekt, język źródłowy, ścieżki plików
babelhub scan                            # nowe klucze w kodzie
babelhub push                            # klucze i wartości źródłowe → serwis
babelhub pull                            # katalogi ← serwis (pliki w repozytorium)
babelhub sync                            # push, potem pull — jedno polecenie na całą pętlę
babelhub status                          # pokrycie per język, QA, gotowość
babelhub validate                        # kontrole jako bramka CI (kod wyjścia 0/≠0)
```

W tym repozytorium CLI uruchamia się bez instalacji: `docker exec ts_app php bin/babelhub …`
(autoloader projektu mapuje `BabelHub\Cli\` na `cli/src/`).

### Trzy sposoby instalacji (1.11)

| Sposób | Dla kogo | Komenda |
| --- | --- | --- |
| `composer global require babelhub/cli` | deweloper PHP (aktualizacje przez Composera) | `composer global require babelhub/cli:~0.1.0` (**nie `^0.1`** — Composer zawęża caret na `0.x` do dokładnej wersji i zainstalowałby `0.1.0`) |
| **binarka PHAR** | ktoś bez Composera, obraz CI, szybka próba | asset z [wydania](https://gitlab.com/babelhub/cli/-/releases) albo `composer install && php -d phar.readonly=0 build-phar.php`; nazwa pliku może być dowolna (binarka adresuje się aliasem archiwum) |
| `bin/babelhub` z **checkoutu tego repozytorium** | praca nad CLI i bramka `composer check` | `php bin/babelhub …` (albo `composer phar` i binarka) |

**PHAR buduje się w repozytorium** (nie wymaga sieci ani Boxa):

```bash
php -d phar.readonly=0 cli/build-phar.php        # → babelhub.phar (~1,7 MB, 10 pakietów)
php babelhub.phar --version                      # BabelHub CLI 0.1.0-dev
php babelhub.phar validate --check=hardcoded-strings --directory=.   # działa bez serwera
```

`-d phar.readonly=0` jest wymagane — PHP domyślnie nie pozwala zapisywać archiwów PHAR
(zabezpieczenie przed podmianą binarki), a skrypt nie próbuje tego obchodzić: mówi wprost,
czego brakuje, i kończy się kodem `1`. Bez tego warunku nie powstaje plik, więc „build przeszedł”
nie może znaczyć „nie ma binarki”.

Co jest w środku: `cli/src/`, `bin/babelhub` i **tylko zależności CLI** — `symfony/console`,
`symfony/yaml` i ich przechodnie domknięcie (10 pakietów), razem z wygenerowanym autoloaderem,
którego ścieżki są względne wobec archiwum. Czego **nie** ma: kodu serwera (`src/Modules/`),
Doctrine'a, Slima i ścieżek absolutnych z maszyny, na której binarka powstała — pilnuje tego
`tests/Cli/PharBuildTest`, który buduje archiwum w osobnym procesie i uruchamia je **poza
repozytorium**.

Plik nie jest commitowany (`.gitignore`): binarka powstaje z `cli/` + `composer.lock`, więc
wydanie dołącza ją jako asset, a repozytorium zostaje źródłem.

## `.babelhub.json` / `.babelhub.yml` — konfiguracja projektu (w repozytorium)

`babelhub init` zapisuje plik, który **przechodzi code review** i mówi, co synchronizować:

```json
{
  "project": "learnhub",
  "source_locale": "en_GB",
  "files": { "pattern": "translations/messages.{lang}.php", "format": "php" },
  "scan": { "paths": ["src", "templates", "config"] }
}
```

Czego w nim **nie ma**: poświadczenia (decyzja D4 — token wklejony do pliku w repo to najczęstsza
droga wycieku) ani adresu serwera (ten sam kod jedzie na staging i produkcję, więc adres należy
do profilu poświadczenia). Walidacja jest **ścisła**: nieznane pole to błąd, bo plik decydujący
o tym, co pojedzie do serwisu, nie może mieć cicho ignorowanej literówki (`sourcelocale`).

`files.pattern` musi zawierać `{lang}` — inaczej `pull` zapisałby wszystkie języki do jednego
pliku i ostatni wygrał. Domyślne `scan.paths` dopasowują się do katalogów, które w projekcie
naprawdę istnieją (a gdy nie ma żadnego z nich, skanujemy całość — pomijając `vendor`,
`node_modules` i cache).

### `.babelhub.yml` — ten sam plik, czytelniejszy dla zespołu

`babelhub init --config=yaml` zapisuje `.babelhub.yml`; oba formaty są równoważne, a CLI
rozpoznaje je po nazwie i szuka **w górę drzewa** tak samo. YAML wybierają zespoły, które chcą
mieć w repozytorium jawny zapis decyzji („co wysyłamy, co pobieramy, co blokuje merge”):

```yaml
project: learnhub
source_locale: en_GB

files:
  pattern: "translations/messages.{lang}.php"
  format: php

scan:
  paths: [src, templates]

upload:                          # push i sync
  mark_missing_as_removed: true   # czy nieobecne w kodzie klucze oznaczać jako usunięte (D11)
  unapprove_changed: false        # czy zmiana tekstu źródłowego cofa zatwierdzenia (REVIEW)

download:                        # pull i sync
  locales: []                     # puste = wszystkie języki projektu
  sort: file                      # file = kolejność z pliku, key = alfabetyczna
  write_nested: false             # true = struktura zagnieżdżona w nowych plikach
  post_process_command: null      # komenda do uruchomienia po zapisaniu plików (np. formatter)

checks:                          # validate
  fail_on: error                  # error albo warning
  run: [quality]                  # które kontrole uruchamia `validate` bez --check
  ignore: [src/Generated]         # ścieżki pomijane przez `hardcoded-strings`
```

**Trzy reguły, które trzeba znać:**

1. **Oba pliki naraz to błąd** (`2`), a nie „jeden wygrywa”. Dwa źródła prawdy o tym, co jedzie
   do serwisu, rozjeżdżają się przy pierwszej zmianie — i to bez komunikatu, tylko tym, że push
   wysyła nie to, co trzeba.
2. **Flaga wygrywa z plikiem.** Plik mówi, jak zespół pracuje na co dzień; `--keep-missing`,
   `--locale` i `--fail-on` są decyzją na jedno uruchomienie. Nigdy odwrotnie — inaczej każdy
   fragment CI musiałby powtarzać ten sam zestaw flag.
3. **Zepsuta konfiguracja to kod `2`, nie `1`.** Zły YAML („A colon cannot be used… at line 2”),
   nieznane pole (`checks.hardcoded`) czy nieznany próg (`fail_on: errorr`) to „popraw plik
   w repozytorium”, a nie awaria narzędzia — a komunikat wskazuje plik i linię.

**Wybrany język, którego projekt nie prowadzi**, też jest błędem użycia: `download.locales: [de_DE]`
w projekcie bez `de_DE` znaczyłoby, że `pull` nie robi nic i wygląda na sukces.

## `babelhub scan` — co kod mówi o tłumaczeniach

```text
$ babelhub scan --show-paths
demo.farewell  src/Greeter.php:17
demo.greeting  src/Greeter.php:12 templates/page.twig:3
demo.subtitle  templates/page.twig:2
demo.title     templates/page.twig:1
Znaleziono 4 kluczy w 2 plikach (katalogi: src, templates).
```

Czyta **wyłącznie lokalne pliki**: nie pyta serwisu, nie potrzebuje poświadczenia i niczego nie
zmienia. Rozpoznaje `trans('k')`, `->trans('k')`, `'k'|trans`, `"k"|trans` oraz
`{% trans %}k{% endtrans %}`; **kluczy dynamicznych nie zgaduje** (`'a.' ~ $x` nie da klucza),
bo fałszywy klucz jest gorszy niż brak klucza — trafiłby do serwisu jako nowy, pusty wpis.

## `babelhub push` — stan kodu do serwisu

```text
$ babelhub push
Skan: 2 plików, 4 kluczy; teksty źródłowe: 4 (en_GB).      ← stderr
cli-demo: nowe 4, zmienione 0, usunięte 0, bez zmian 0 (teksty źródłowe zmienione: 4)   ← stdout

$ babelhub push                                            ← powtórzenie
cli-demo: nowe 0, zmienione 0, usunięte 0, bez zmian 4 (teksty źródłowe zmienione: 0)
```

* partia to **suma** kluczy ze skanu i kluczy z pliku źródłowego — klucz używany dynamicznie
  jest w pliku, choć nie ma go w kodzie jako literał, a wysłanie samego skanu oznaczyłoby go
  jako „zniknął z kodu”;
* `--dry-run` pokazuje, co poleciałoby do serwisu, i nie wysyła niczego;
* `--keep-missing` wyłącza oznaczanie nieobecnych kluczy (przydatne przy pushu jednego modułu);
* `--unapprove-changed` cofa zatwierdzenia w językach docelowych, gdy tekst źródłowy się zmienił
  — patrz „co się dzieje z zatwierdzeniem” niżej;
* pominięte klucze → kod wyjścia `6`, nie `0`: „push przeszedł” nie może znaczyć „nie wiem,
  czy wszystko dotarło”.

**Powtórzenie nic nie psuje** — endpoint jest idempotentny, a `bez zmian: N` jest dowodem, że
pipeline nie podbija wersji katalogu przy każdym commicie (co unieważniałoby cache u klientów).

### Znacznik synchronizacji w Git (1.18) — po co drugi push ma nie wysyłać niczego

Powtórzenie jest bezpieczne, ale nie jest **darmowe**: każde uruchomienie wysyła cały katalog,
a serwis porównuje go klucz po kluczu. Pipeline, który odpala się przy każdym commicie (także
w plikach, które tłumaczeń nie dotyczą), płaci za to za każdym razem — więc `push` pamięta, co
już poszło:

```text
$ babelhub push
Znacznik babelhub/sync/main → 1a2b3c4 (main).              ← stderr

$ babelhub push                                            ← ten sam commit, czyste drzewo kodu
Nic do wysłania: 1a2b3c4 (main) jest już zsynchronizowany (babelhub/sync/main). Użyj --force, żeby wysłać ponownie.

$ babelhub push --output=json                              ← to samo maszynowo
{"skipped":true,"reason":"git","project":"learnhub","commit":"1a2b3c4…","branch":"main","marker":"babelhub/sync/main"}
```

| Reguła | Dlaczego tak |
| --- | --- |
| Znacznik to **tag `babelhub/sync/<gałąź>`**, przesuwany po każdym udanym pushu | jeden tag na gałąź, bez zarastania repozytorium; nie wymaga uprawnień do zapisu w zdalnym repo |
| Pominięcie liczy się **tylko dla plików, które `push` czyta** (drzewo skanu + plik źródłowy) | `sync` po `pull` zostawia zmienione pliki języków docelowych — patrzenie na całe drzewo wyłączyłoby mechanizm po pierwszym przebiegu |
| Pominięcie wymaga **czystych** tych plików | niezacommitowana zmiana w kodzie to nowy stan, nawet gdy HEAD się nie ruszył |
| Znacznik przesuwa się **tylko po pushu kompletnym** (kod `0`, zero pominiętych kluczy) | inaczej nieudany przebieg zamroziłby stan i następny powiedziałby „już wysłane” o partii, która dotarła częściowo |
| Brak gita, brak gałęzi (odłączony HEAD), brak `git` w PATH → **zwykły push** | „nie wiem” nie może znaczyć „już wysłane”, bo po cichu wstrzymałoby synchronizację |
| Pominięcie to kod **`0`** | to nie błąd, tylko brak pracy; `--force` jest na wypadek, gdy chcesz wysłać ten sam commit ponownie |

| Flaga | Znaczenie |
| --- | --- |
| `--force` | wyślij, nawet gdy znacznik mówi, że ten commit już poszedł |
| `--no-git-tag` | nie czytaj i nie przesuwaj znacznika (wyłącza cały mechanizm) |
| `--push-tag` | po pushu wyślij znacznik do zdalnego repo (`git push --force origin refs/tags/…`) |

**Kiedy `--push-tag` ma sens.** Znacznik działa tam, gdzie klon przetrwa między przebiegami:
lokalnie, w GitLab CI z `GIT_STRATEGY=fetch`, na runnerach własnych. Świeży, płytki klon
(domyślne GitHub Actions) tagów nie ma, więc mechanizm nic nie oszczędza — chyba że znacznik
dojedzie do zdalnego repo. To **opcja**, bo zapis do zdalnego repo to uprawnienia i widoczny
ślad; niepowodzenie wysłania znacznika **nie jest** niepowodzeniem pushu (tłumaczenia są
w serwisie), więc kończy się ostrzeżeniem, a nie błędem. `--no-git-tag` z `--push-tag` to
sprzeczność i kod `2`.

### Co się dzieje z zatwierdzeniem, gdy zmieni się tekst źródłowy

To pytanie z planu (1.19) i domyślna odpowiedź brzmi: **nic**.

| Ustawienie | Zachowanie |
| --- | --- |
| domyślnie | zatwierdzone tłumaczenia **zostają** zatwierdzone; licznik `source_changed` mówi, ile tekstów źródłowych się zmieniło |
| `--unapprove-changed` (na jedno uruchomienie) | zatwierdzenia w językach docelowych wracają do `REVIEW`, licznik `unapproved` mówi, ile |
| `upload.unapprove_changed: true` (w repozytorium) | to samo, ale na stałe — decyzja zespołu |

Dlaczego domyślnie „nic”: zmiana tekstu źródłowego to **fakt z kodu**, a zatwierdzenie to
**decyzja człowieka**. Większość zmian to literówki i doprecyzowania, przy których istniejące
tłumaczenie jest nadal dobre — a `push` chodzi w CI po każdym commicie, więc cofanie zatwierdzeń
„na wszelki wypadek” zamieniałoby każdy przebieg w kolejkę pracy dla recenzentów. Zespół, który
chce pewności, że nic nie poszło na produkcję bez ponownego przeglądu, włącza to **świadomie** —
wtedy `push` odpowiada na to pytanie liczbą (`unapproved`), a nie milczeniem.

Język **źródłowy** zostaje przy tym zatwierdzony: jego wartość jest faktem z kodu, nie
tłumaczeniem do przejrzenia. Tekst w `DRAFT`/`REVIEW` też zostaje w spokoju — już czeka
na człowieka, a licznik kłamałby o odebranej pracy.

## `babelhub pull` — katalogi do plików, bez szumu w diffie

```text
$ babelhub pull
en_GB    zapisane (91 kluczy, wersja 2)
pl_PL    zapisane (91 kluczy, wersja 13)

$ babelhub pull                       ← powtórzenie
en_GB    bez zmian (wersja 2)
pl_PL    bez zmian (wersja 13)
```

* **`pull` nie dotyka pliku, gdy katalog się nie zmienił** — pyta z `If-None-Match` wersją
  z `.babelhub.lock.json`, dostaje `304` i nic nie zapisuje. Sprawdzone pomiarem `mtime`:
  po powtórzeniu plik jest nietknięty, więc w repozytorium nie ma diffu z niczego;
* **zapis jest atomowy** (plik tymczasowy + `rename`), bo przerwany zapis pliku językowego to
  uszkodzenie, które i tak trafi do commita („plik się zmienił”);
* **pusty katalog nie kasuje pliku** — język bez zatwierdzonych tekstów jest raportowany jako
  `brak zatwierdzonych tekstów — plik nietknięty`, bo zapis pustej tablicy skasowałby pracę,
  której nikt jeszcze nie zatwierdził;
* `--check` **nic nie zapisuje**: tylko sprawdza, czy repozytorium odpowiada serwisowi, i kończy
  się kodem `6` przy rozjazdzie. To tryb dla CI („czy ktoś zapomniał zrobić pull”).

### Format pliku należy do projektu, nie do nas

To jest pozycja 1.16b planu i jednocześnie najczęstszy powód, dla którego ludzie przeklinają
narzędzia tej kategorii: narzędzie, które przy każdym przebiegu przepisuje plik „po swojemu”,
zamienia każdy pull request w konflikt. Dlatego `pull` **czyta styl z pliku, który już jest**,
i pisze w nim dalej:

| Zachowywane | Skąd wiemy |
| --- | --- |
| końce linii (LF albo CRLF) | z pliku; przy mieszanych wygrywa większość |
| końcowy znak nowej linii (jest / nie ma) | z pliku |
| wcięcia (spacje — ile, albo tabulator) | z pierwszej wciętej linii |
| struktura: płaska (`"nav.title"`) albo zagnieżdżona (`{"nav":{"title":…}}`) | z pliku |
| kolejność kluczy | z pliku: istniejące zostają na miejscach, nowe dochodzą na końcu |
| `declare(strict_types=1)`, `array()` vs `[]` | z pliku (PHP) |
| **klucze, których katalog nie zna** | z pliku — zostają na swoich miejscach |

Ostatni wiersz jest najważniejszy: plik językowy w repozytorium bywa ręcznie uzupełniany (tekst
jeszcze niezatwierdzony, klucz dodany lokalnie), a `pull` **nie jest właścicielem tego pliku**.
Skasowanie cudzej pracy bez pytania byłoby najdroższym błędem, jaki to narzędzie mogłoby
popełnić. Zepsuty plik też nie blokuje pracy: nie da się z niego odczytać stylu, więc `pull`
zapisuje go w formacie domyślnym — czyli naprawia.

**Jedno świadome odstępstwo: cudzysłowy w PHP.** Wartości idą przez `var_export`, więc zawsze
w apostrofach. Własny escaper byłby tańszy w diffie i droższy w skutkach — to w nim narzędzia tej
kategorii po cichu psują teksty z apostrofem („It's done”) i ze znakiem nowej linii, a błąd widać
dopiero w aplikacji.

### Opcje zapisu (`download` w `.babelhub.yml`)

```yaml
files:
  pattern: "translations/{lang}/{ns}.json"   # {lang} = język, {ns} = przestrzeń nazw klucza
  format: json
download:
  locales: [pl_PL]        # puste = wszystkie języki projektu
  sort: file              # file (domyślnie) albo key
  write_nested: false     # true = struktura zagnieżdżona w nowych plikach
```

* **`{ns}`** rozdziela katalog na **osobny plik na przestrzeń nazw** — pierwszy segment klucza
  przed kropką (`nav.title` → `nav.json`). Tak trzymają tłumaczenia projekty z i18next, a bez tego
  `pull` wypisałby wszystko do jednego pliku. Wzorzec bez `{ns}` znaczy „wszystko razem”
  (dotychczasowe zachowanie). Placeholdery są zamkniętą listą: literówka (`{format}`) to błąd
  konfiguracji z listą tego, co wolno wpisać;
* **`sort: file`** (domyślnie) to kolejność z pliku — patrz tabela wyżej. **`sort: key`** porządkuje
  wszystko alfabetycznie; to decyzja zespołu, więc wygrywa z plikiem i robi jednorazowy diff
  porządkujący;
* **`write_nested: true`** pisze strukturę drzewa także w **nowym** pliku (w istniejącym struktura
  i tak jest zachowywana). Kolizja „klucz jest i liściem, i gałęzią” (`nav` oraz `nav.title`)
  kończy się tym, że dłuższy klucz zostaje pod pełną nazwą — ciche zgubienie tekstu byłoby gorsze
  niż brzydki klucz w pliku;
* **każdy plik ma własny styl**: projekt może mieć `common.json` z dwoma spacjami i `admin.json`
  z czterema i to nie jest nasza sprawa.

Czego **nie ma**: `EXCLUDE_DEPRECATED_KEYS` (klucze oznaczone jako nieobecne w kodzie, D11).
Katalog zatwierdzony ich nie rozróżnia — odpowiedź `GET …/translations/{locale}` nie mówi, które
klucze są `removed` — więc opcja wymaga najpierw rozszerzenia kontraktu API. Zostaje w `TODO.md`
razem z tym powodem.

### `download.post_process_command` — własne formatowanie po zapisie

```yaml
download:
  post_process_command: "php bin/format-translations"
```

Po zapisaniu plików `pull` (a więc i `sync`) oddaje sterowanie komendzie z repozytorium. Po co:
formatowanie plików językowych bywa sprawą łańcucha budowania klienta (`prettier`,
`php-cs-fixer`, własny skrypt), a bez tego hook-a **każde CI musi pamiętać o drugim kroku** —
i ten, kto zapomni, commituje niesformatowany plik, dowiadując się o tym z różnicy w następnym
PR-ze.

* **komenda idzie przez powłokę, w katalogu projektu** — ta sama kategoria zaufania co
  `composer.json` (``scripts``), `Makefile` czy `npm run`. Nic z danych (klucze, języki, odpowiedź
  serwisu) nie jest do niej podstawiane: konfiguracja nie może być miejscem wstrzyknięcia kodu;
* **uruchamia się tylko wtedy, gdy coś naprawdę zapisano** — przy samych `304` nie ma czego
  formatować, a `prettier` przepisujący niezmienione pliki to diff z niczego;
* **niepowodzenie nie udaje nieudanego pobrania**: pliki są zapisane, `.babelhub.lock.json`
  zaktualizowany, a kod wyjścia to `1` („narzędzie nie dokończyło pracy”), nie `6`
  („tłumaczenia są złe”). Na `stderr` idą kod wyjścia hook-a i jego wyjście (obcięte do 2000 znaków);
* **skrypt, który wisi, jest ubijany po 300 s** (`124`, konwencja `timeout(1)`) — hook czekający
  na wejście z terminala wiesza pipeline **na zawsze**, a takiej awarii nie widać w logu;
* **`--output=json` niesie `post_process`**: `{"command": …, "ran": true, "exit_code": 0,
  "timed_out": false}` (albo `null`, gdy hook-a nie ma / nie było czego zapisywać).

### `.babelhub.lock.json` — który commit ma które wersje

```json
{"project": "learnhub",
 "locales": {"en_GB": {"version": 2,  "keys": 91, "pulled_at": "…"},
             "pl_PL": {"version": 13, "keys": 91, "pulled_at": "…"}}}
```

To odpowiedź na pytanie „czy umiesz dać mi tłumaczenia dla konkretnego commita?”: **tak, i bez
pytania serwera** — pliki językowe *i* lock leżą w repozytorium, więc `git checkout <commit>` daje
komplet, a `pull --check` potwierdza zgodność z serwisu.

**Czego nie ma: `pull --version=N`.** Serwis trzyma **jedną** wersję na (projekt, język) —
historię treści planujemy dopiero w Fali 3 (`translation_revisions`). Polecenie, które
„odtwarza wersję 7” z bazy, w której jest 12, działałoby i zwracało coś innego niż obiecuje;
dlatego pin jest w commicie, a nie w fladze. (Świadome odejście od brzmienia 1.16a planu.)

## `babelhub sync` — cała pętla jednym poleceniem

```text
$ babelhub sync
— push —                                    ← najpierw kod trafia do serwisu
cli-demo: nowe 4, zmienione 0, usunięte 0, bez zmian 0
— pull —                                    ← potem wracają gotowe tłumaczenia
en_GB    zapisane (4 kluczy, wersja 1)
```

Kolejność ma znaczenie: `pull` pobiera wyłącznie teksty **zatwierdzone**, więc nowy klucz
dodany w kodzie i tak nie wróci — a gdyby `pull` szedł pierwszy, deweloper patrzyłby na stan
sprzed własnej zmiany. Polecenie **przerywa się na pierwszym błędzie**: `push`, który zwrócił
`6`, nie jest przepuszczany dalej, bo `pull` na niekompletnym stanie daje pliki, których nikt
nie zweryfikował.

## `babelhub diff` — co się zmieniło od wskazanej chwili

```bash
babelhub diff                      # od ostatniego `pull` (data z .babelhub.lock.json)
babelhub diff --since=2026-09-01T00:00:00Z
babelhub diff --output=json        # ciało odpowiedzi serwisu, dosłownie
```

Trzy kubełki: `new` (klucz pojawił się w kodzie), `changed` (**tekst źródłowy** się zmienił, więc
tłumaczenie może być nieaktualne), `removed` (klucza nie ma w kodzie — zostaje w katalogu razem
z tłumaczeniami). W trybie `plain` każda pozycja to jedna linia z prefiksem kubełka
(`new a.one`, `changed b.two`), więc log CI da się filtrować bez parsowania JSON-a.

Trzy rzeczy warte zapamiętania:

* **`--since` jest wymagane, gdy nie ma locka.** Bez punktu odniesienia diff wyglądałby jak
  „nic się nie zmieniło”, a znaczyłby „nie wiem od kiedy” — dlatego to błąd użycia (kod `2`)
  z podpowiedzią, a nie pusta lista;
* **kod wyjścia to `0` także wtedy, gdy coś się zmieniło.** `diff` jest odczytem; bramką jest
  `validate`. Gdyby `diff` zwracał `6` przy zmianach, nikt nie uruchamiałby go w CI;
* **liczby przy uciętej liście mają plus** (`New: 500+`), bo listy są ograniczone do 500 pozycji
  na kubełek, a liczba bez tego znacznika kłamałaby.

## `babelhub status` — wydruk z §10 planu

```text
QA demo
────────────────────────────

Locales

en_GB  100%   ready
pl_PL  100%   ready

Keys
────────────────────────────

Total:        1
Translated:   2  (klucz × język)
Missing:      0  (klucz × język)
Review:       0

QA errors:        0
QA warnings:      1

Last sync:        2026-09-14T09:13:27+00:00
```

`Total` liczy **klucze**, a `Translated`/`Missing` **tłumaczenia** (klucz × język) — jednostka
jest w nawiasie, bo bez niej te liczby wyglądają na sprzeczne. `ready` znaczy dokładnie to samo
co `complete` w manifeście **i** brak błędów QA: jedna definicja „gotowego języka”, dwa miejsca
użycia. Ostrzeżenia nie odbierają gotowości (blokuje tylko `BŁĄD`).

`status` **niczego nie zmienia** i kończy się zerem także wtedy, gdy projekt nie jest gotowy —
to odczyt, a bramką jest `validate`. Mieszanie tych dwóch rzeczy kończy się tym, że nikt nie
uruchamia statusu w CI, bo „psuje build”.

## `babelhub validate` — bramka CI

```text
$ babelhub validate
ERROR placeholder_missing billing.plan.monthly — W kluczu „billing.plan.monthly” brakuje placeholderów ze źródła: amount.
Kontrola jakości: 1 błędów, 0 ostrzeżeń, 0 informacji.
$ echo $?
6
```

* `--locale=pl_PL` zawęża kontrolę do jednego języka (w CI sprawdza się zwykle ten, który właśnie
  się zmienił);
* `--fail-on=error|warning` ustawia próg: domyślnie blokuje tylko `BŁĄD`, bo fałszywy alarm
  blokujący merge uczy ludzi obchodzenia bramki — a wtedy kontrola przestaje istnieć;
* `--output=plain` daje jedną linię na ustalenie (`WAGA kod język klucz — zdanie`), więc log CI
  da się filtrować bez parsowania JSON-a.

Zakresy kontrolek QA (plan §12 MVP): brakujące, puste, placeholdery (pięć notacji: `{name}`,
`{{ name }}`, `%name%`, `:name`, `%s`), znaczniki HTML, składnia ICU i ten sam tekst przy różnym
źródle. Kody ustaleń (`missing`, `placeholder_missing`, `icu_syntax`, …) są **kontraktem** —
stabilne i wąskie, więc CI może się po nich rozgałęziać.

### Cztery nazwane kontrole (`--check=…`)

`babelhub validate` bez flagi uruchamia zestaw z `checks.run` (bez tego pola: `quality`), a
`--check=<nazwa>` wskazuje jedną kontrolę na to uruchomienie. Nazwa jest kontraktem, bo trafia do
kroku w CI: developer widzi „BabelHub / coverage” na czerwono i **wie, co blokuje merge**, nie
otwierając logu.

| Kontrola | Pytanie, na które odpowiada | Skąd dane | Zakres | Waga ustaleń | Poświadczenie |
| --- | --- | --- | --- | --- | --- |
| `quality` | czy to, co jest przetłumaczone, jest poprawne | `POST …/qa` | `qa:read` | `error` / `warning` / `info` | tak |
| `coverage` | czy czegoś **nie brakuje** | `POST …/qa`, kody `missing` | `qa:read` | `error` | tak |
| `acceptance` | czy coś **czeka na zatwierdzenie** | `GET …/status`, liczniki statusów | `translations:read` | `warning` | tak |
| `hardcoded-strings` | czy tekst nie jest wpisany wprost w kodzie | pliki w repozytorium | — | `warning` | **nie** |

Trzy decyzje, które stoją za tą tabelą:

* **`coverage` nie ma własnej definicji „brakuje”.** To widok raportu QA na ustalenia o kodzie
  `missing` — gdyby reguła „brak” zmieniła się w serwisie, a tutaj została przepisana, panel
  i bramka mówiłyby o brakach co innego.
* **`acceptance` czyta status, nie QA.** QA liczy ustalenia wobec wartości **zatwierdzonych**, więc
  draft wygląda tam jak brak tłumaczenia. Dla człowieka to jednak dwie różne sytuacje i dwie różne
  osoby do zadania: brak tłumaczenia to robota tłumacza, brak zatwierdzenia — recenzenta.
  Ustalenie ma kod `unapproved` i jest **ostrzeżeniem**: niezatwierdzona wartość to zwykle praca
  w toku, a nie defekt. Twarda bramka („na main nie wchodzi nic niezatwierdzonego”) to jawne
  `--fail-on=warning` w CI zespołu.
* **Zestaw kontroli deklaruje repozytorium** (`checks.run`), bo repo wie, czym jest jego bramka,
  a CI bywa cztery razy inne. Jedna kontrola na wywołanie (`--check`) to nie ograniczenie:
  osobne kroki dają osobną nazwę na liście checków, a zestaw w pliku pozwala uruchomić wszystko
  jednym `babelhub validate` (jedno żądanie na endpoint — `quality` i `coverage` czytają ten sam
  raport QA, więc nie płacą dwa razy).

Przy zestawie kilku kontroli werdykt nazywa **każdą**, która zatrzymała bramkę:

```text
$ babelhub validate --fail-on=warning
WARNING hardcoded_string src/Controller.php:3 — Tekst wpisany w kodzie: „Welcome back to your dashboard”.
Kontrola hardcoded-strings — ostrzeżeń: 1, plików: 1.
Kontrola coverage — brakujących tłumaczeń: 0.
Kontrola acceptance — niezatwierdzonych tłumaczeń: 12 (pl_PL: 12).
$ echo $?     # na stderr: „Lokalizacja nie przeszła kontroli: hardcoded-strings (1 ostrzeżeń).”
6
```

`checks.run: []` jest **błędem konfiguracji** (kod `2`), nie „domyślnym zestawem”: pusta lista to
bramka, która zawsze przechodzi — gorsza niż jej brak, bo wygląda na sprawdzenie.

### `--output=json` — jeden dokument o stałym kształcie

Kształt nie zależy od tego, ile kontroli uruchomiono (skrypt, który dopisze `coverage` do
`checks.run`, nie może z dnia na dzień dostać innego dokumentu):

```json
{
  "checks": {
    "coverage": {
      "check": "coverage",
      "pass": false,
      "blocking": 1,
      "summary": {"missing": 1, "locales": {"pl_PL": 1}},
      "findings": [
        {"code": "missing", "severity": "error", "key": "billing.plan", "locale": "pl_PL",
         "message": "Brak zatwierdzonego tłumaczenia dla klucza „billing.plan”.", "params": {}}
      ]
    },
    "quality": {
      "check": "quality", "pass": false, "blocking": 1,
      "summary": {"errors": 1, "warnings": 0, "info": 0, "total": 1},
      "findings": [ "…to samo, w kształcie kontraktu API…" ],
      "report": { "…dosłowne ciało odpowiedzi POST …/qa…" }
    }
  },
  "pass": false,
  "fail_on": "error",
  "failures": ["coverage", "quality"]
}
```

* `blocking` to liczba ustaleń, które zatrzymały bramkę **przy tym progu** — tego nie da się
  wyliczyć z `summary`, bo próg jest decyzją wywołującego, dlatego `fail_on` stoi w dokumencie;
* `failures` nazywa kontrole, które nie przeszły (`jq '.failures'` w kroku CI);
* `report` dochodzi tylko tam, gdzie serwis zwraca cały dokument: to jego **dosłowne** ciało
  (pusta mapa `params` zostaje `{}`, a nie staje się pustą listą), więc nic z kontraktu API nie
  ginie po drodze. Uwaga: `checks.*.findings` to widok znormalizowany — `params` jest tam mapą
  odtworzoną z JSON-a.

### `--check=hardcoded-strings` — kontrola, która czyta kod, nie serwis

```text
$ babelhub validate --check=hardcoded-strings
WARNING hardcoded_string src/Controller.php:3 — Tekst wpisany w kodzie: „Welcome back to your dashboard”.
WARNING hardcoded_string templates/page.twig:3 — Tekst wpisany w kodzie: „Your subscription is almost over”.
Kontrola hardcoded-strings — ostrzeżeń: 2, plików: 2.
$ echo $?
0                                  # ostrzeżenie nie blokuje domyślnej bramki
$ babelhub validate --check=hardcoded-strings --fail-on=warning; echo $?
6
```

To jedyna kontrola, która działa **u źródła**: tekst wpisany wprost w kodzie nie trafia do
serwisu przez `push`, więc nie widzi go żadna z pozostałych (brakujące, niezatwierdzone,
placeholdery pilnują tekstów, które już są w katalogu). Dwie rzeczy wynikają z tego wprost:

* **nie potrzebuje poświadczenia ani połączenia** — czy tekst jest w kodzie, wie tylko kod.
  W CI może stać **przed** `login` i działa, gdy serwis jest niedostępny;
* **jest heurystyką i dlatego nie blokuje domyślnie.** Rozpoznanie „to jest tekst dla człowieka”
  wymagałoby typu zmiennej, a mamy napisy w plikach — więc zgłaszamy tylko to, co **wygląda na
  zdanie**: ma spację, co najmniej kilka liter, zaczyna się wielką literą i nie jest żadnym
  z technicznych kształtów (ścieżka, URL, klucz tłumaczenia, fragment kodu, nagłówek HTTP,
  format daty, zapytanie SQL). Komentarze, literały w blokach Twiga i komunikaty dla developera
  (`throw new`, `assert`) są pomijane.

Wagi i próg: `hardcoded_string` to **ostrzeżenie**, więc domyślny próg (`error`) je przepuszcza,
a `--fail-on=warning` (albo `checks.fail_on: warning` w repozytorium) blokuje merge. Lista jest
obcinana do 30 pozycji w trybie `text`/`plain`; pełna jest w `--output=json`, a **liczby są
zawsze pełne**.

Wyjątki dla kodu, którego nikt nie chce przepisywać (generowany, migawki, stary moduł), deklaruje
się w repozytorium — jawnie, bo inaczej ludzie wyłączą kontrolę na całe repo i nie będzie jej wcale:

```yaml
checks:
  run: [hardcoded-strings, coverage, acceptance]
  fail_on: error
  ignore: [src/Generated, src/Legacy]
```

## Poświadczenie: trzy źródła, jedna kolejność

| Priorytet | Źródło | Kiedy |
| --- | --- | --- |
| 1 | `--token=<pat>` | konta serwisowe, jednorazowe skrypty |
| 2 | `BABELHUB_TOKEN` | CI — poświadczenie w zmiennej, nigdy w pliku |
| 3 | `~/.config/babelhub/credentials.json` | praca lokalna, plik z parowania (prawa `0600`) |

Adres serwisu analogicznie: `--api-url` → `BABELHUB_API_URL` → zapisane poświadczenie.

**`.babelhub.json` w repozytorium nigdy nie zawiera poświadczenia.** Ten plik jest konfiguracją
projektu (slug, język źródłowy, ścieżki) i przechodzi code review; poświadczenie żyje poza
repozytorium i tak ma zostać (decyzja D4 planu). Pierwszy odruch developera — wklejenie tokenu
do pliku w repo — jest dokładnie tym, przed czym ten podział chroni.

### Parowanie w przeglądarce (`babelhub login`)

```text
$ babelhub login
Kod parowania: PX7F-DZGG
Zatwierdź go w panelu: https://babelhub.example/cli/authorize?code=PX7F-DZGG
Czekam na zatwierdzenie w przeglądarce…
Zalogowano jako Mikołaj Panecki w organizacji Acme.
```

Terminal prosi o kod, człowiek zatwierdza go w panelu (będąc zalogowanym i widząc, **co** prosi
o dostęp, **o jakie zakresy** i **do jakiej organizacji**), a poświadczenie wraca **do terminala,
który o nie poprosił**. Nikt niczego nie kopiuje — nie ma więc czego wkleić w złe miejsce.
Kod żyje 10 minut i działa raz.

Bez TTY (CI, SSH) pierwsze polecenie wypisuje JSON i kończy się kodem `7`:

```json
{"browser_url": "…", "verification_code": "PX7F-DZGG", "next_step": "babelhub login --complete=…"}
```

Po zatwierdzeniu w przeglądarce parowanie dokańcza się na tej samej maszynie:

```bash
babelhub login --complete="https://babelhub.example/cli/authorize?code=PX7F-DZGG"
```

`device_code` (sekret terminala) **nigdy nie opuszcza maszyny** — leży w pliku stanu parowania
(`~/.config/babelhub/pending-pairing.json`, `0600`), kasowanym w chwili odebrania poświadczenia.
Adres z przeglądarki służy wyłącznie do potwierdzenia, **który** kod człowiek zatwierdził:
gdyby wystarczał do odebrania tokenu, każdy, kto zobaczy adres (historia przeglądarki, `Referer`,
zrzut ekranu), odebrałby cudze poświadczenie.

## Kontrakt kodów wyjścia

Nie „opublikowana tabela”, a zachowanie pokryte testami (`tests/Cli/`) — to jedyne miejsce,
w którym możemy być sprawdzalnie lepsi od całej kategorii (§1.16 planu).

| Kod | Znaczenie | Co robi pipeline |
| --- | --- | --- |
| `0` | zrobione | idzie dalej |
| `1` | błąd nieprzewidziany (sieć, dysk, serwis `5xx`) | pokaż człowiekowi |
| `2` | błąd użycia albo konfiguracji (flaga, brak `.babelhub.json`, brak poświadczenia) | popraw wywołanie |
| `3` | `401`/`403`: poświadczenie nieznane, wygasłe, odwołane albo bez zakresu | napraw poświadczenie |
| `4` | `429`: limiter kazał poczekać | poczekaj `Retry-After` i powtórz |
| `5` | inne odrzucenie przez API (`404`, `422`) | popraw dane |
| `6` | kontrole przeszły i **coś znalazły** (`validate`, niepełny `push`, rozjazd `pull --check`) | bramka: nie merguj |
| `7` | parowanie czeka na człowieka | dokończ przez `--complete=<url>` |

Reguła nadrzędna: **`0` znaczy dokładnie „zrobione”**. Powodzenie częściowe (np. `push`, który
wysłał 90 z 100 kluczy) nie jest zerem — inaczej nikt nie wróci do brakujących dziesięciu.

**Zdanie pochodzi z ciała odpowiedzi serwisu, nie z kodu HTTP.** `500` z komunikatem „nie mogę
zapisać katalogu” pokazuje ten komunikat; `HTTP 500` nie mówi człowiekowi nic, czego nie wie już
z kodu wyjścia. Gdy ciała nie ma (proxy, HTML), komunikat mówi `HTTP 502` i tyle — bez zgadywania.

**Trzy rzeczy, które wyglądają na szczegóły, a są kontraktem** (bo każda z nich komuś kiedyś
zepsuła pipeline):

* **`--version` mówi prawdę** — wypisuje stałą z `cli/src/Version.php`, tę samą, którą CLI wysyła
  serwisowi w nagłówku `User-Agent`. Nigdy `UNKNOWN` (domyślne Symfony, gdy aplikacja nie ma
  wersji) i nigdy wartość wymyślona: warunki `if` w skryptach stoją właśnie na tej liczbie.
* **`--timeout` faktycznie dociera do klienta HTTP.** Domyślnie 15 s na odpowiedź i 5 s na
  połączenie; `--timeout=<sekundy>` skraca albo wydłuża limit. Wartość nieliczbowa (`60s`) albo
  zerowa to **błąd użycia (`2`)**, a nie ciche podstawienie domyślnej: `--timeout=60s` dawałoby
  15 sekund i „losowe” zrywanie długich żądań, którego nikt nie znajdzie w logu.
* **Błąd użycia to `2`, nigdy `1`.** Dotyczy także nieznanej flagi — a tu jest pułapka, przez
  którą wcześniej wychodziło `1`: `ArgvInput` rzuca przy nieznanej opcji
  `Symfony\...\Exception\RuntimeException`, a `ArrayInput` (używany w testach)
  `InvalidArgumentException`. Ta sama pomyłka człowieka dawała więc `2` w teście i `1`
  u użytkownika. Łapiemy **wszystkie** wyjątki Symfony Console (wspólny znacznik
  `ConsoleException`) i mapujemy je na `2`, a nie na klasy po komunikatach.

## Wyjście: `--output=text|plain|json`

* `text` (domyślnie) — dla człowieka;
* `plain` — linie do `grep`/`awk`, bez ozdób;
* `json` — **dokładnie jeden** obiekt JSON na `stdout`.

Wynik idzie na `stdout`, a postęp, ostrzeżenia i błędy na `stderr` — w każdym trybie. Bez tego
podziału `plain` i `json` przestają nadawać się do parsowania, a to ich jedyne zastosowanie.

Dwie reguły, które testy sprawdzają w poprzek **wszystkich** komend
(`tests/Cli/CommandContractTest.php`):

* **niepowodzenie nie wypisuje niczego na `stdout`** — ani w `json` (połowa dokumentu jest gorsza
  niż brak dokumentu, bo parser dostaje obiekt bez pól i „działa”), ani w `plain`;
* **każda komenda ma wynik maszynowy**, także `login` i `logout`: `--output=json` na `stdout`
  zawsze coś mówi (`logged_in`, `logged_out` + `removed`), bo skrypt nie ma innego sposobu, żeby
  odróżnić „nie było czego kasować” od „skasowałem”.

## Timeout

Domyślnie 15 s na odpowiedź i 5 s na połączenie (`--timeout=<sekundy>`). Świadomie krótko:
narzędzie, które wiesza pipeline na kilkaset sekund (realna skarga na CLI Lokalise: 700 s
bez możliwości skrócenia), jest w CI gorsze niż brak narzędzia.

Limit jest przekazywany do klienta HTTP **jednym seamem** (`ApiSession::client($credentials, $timeout)`
→ `ApiClient`), bo wcześniej czytała go wyłącznie metoda, której nikt nie wołał — flaga istniała,
a nie robiła nic. Pilnuje tego test, który podstawia własną fabrykę klienta i sprawdza wartość,
z jaką komenda go zbudowała.

## `--version`

`babelhub --version` wypisuje wersję wydania z jednej stałej (`cli/src/Version.php`). Nie jest
wyciągana z `composer.lock` ani z Git-a, bo w obrazie CI tych danych może nie być — a kłamiąca
wersja potrafi latami psuć skrypty, które warunkują od niej kroki. Test pilnuje trzech rzeczy
naraz: formatu wersji, zgodności z tym, co wypisuje `--version`, i obecności tej samej wartości
w `User-Agent`.

## Gdzie to jest w kodzie

```text
bin/babelhub                     wejście (autoload + kod wyjścia)
cli/src/Console/                 aplikacja i komendy (login, logout, whoami, init, scan, push)
cli/src/Application/             logika komend, którą da się przetestować bez sieci (CliLogin, CliPush)
cli/src/Checks/                  nazwane kontrole: CheckName, ustalenia, werdykt, skaner kodu
cli/src/Config/                  poświadczenie, stan parowania i `.babelhub.json`
cli/src/Files/                   czytanie i zapis plików językowych (php, json) — atomowo
cli/src/Scan/                    adapter PHP/Twig: klucze w kodzie + miejsce wystąpienia
cli/src/Http/                    klient curl + mapowanie odpowiedzi i błędów
cli/src/Output/                  tryby wyjścia i rozdział strumieni
cli/composer.json                manifest pakietu (przyszłe publiczne repo, 1.11)
tests/Cli/                       zachowanie CLI: kody wyjścia, pliki, strumienie
```

Sercem bramki jest `cli/src/Application/CliValidate.php` (zbiera komplet kontroli, drukuje wynik
i wydaje werdykt), a nazwy i wagi siedzą w `cli/src/Checks/` — jedno miejsce na cztery kontrole,
żeby `--help`, `checks.run` i test kontraktu fragmentów CI czytały tę samą listę.

Strona serwisowa parowania: `Identity/.../CliAuthApiController` (publiczne `/api/v1/cli/*`),
`CliAuthorizeController` (ekran w panelu), `PersonalAccessTokens` + `CliPairing` (logika),
`PersonalAccessTokenMiddleware` (uwierzytelnianie `/api/v1` tokenem).

## Włączenie CLI do CI aplikacji

Gotowe fragmenty (GitHub Actions, GitLab CI) i instrukcja — `docs/ci.md`, pliki
`examples/ci/`. W skrócie: sekret `BABELHUB_TOKEN`, na PR cztery **nazwane** kontrole
(`--check=hardcoded-strings|coverage|quality|acceptance`, kod `6` blokuje merge — a nazwa kroku
mówi, która), na gałęzi głównej `sync` + commit zwrotny plików tłumaczeń razem
z `.babelhub.lock.json`. Jedna kontrola na krok daje osobną nazwę na liście checków; zestaw
z `checks.run` pozwala to samo uruchomić jednym `babelhub validate`.
