# Fragmenty CI dla aplikacji korzystających z BabelHub

Te pliki **nie są** CI tego repozytorium — CI repozytorium CLI leży w `.gitlab-ci.yml`.
To gotowe do skopiowania konfiguracje dla repozytorium, które **korzysta** z BabelHub:

| Plik | Gdzie go wkleić |
| --- | --- |
| `github-actions.yml` | `<twoje-repo>/.github/workflows/babelhub.yml` |
| `gitlab-ci.yml` | `<twoje-repo>/.gitlab-ci.yml` (albo `include:`) |

Oba robią to samo i używają dokładnie tych samych poleceń CLI:

* na **pull requeście / merge requeście**: `status` (raport), `push --dry-run` (co kod wniósłby
  do serwisu) i **cztery nazwane kontrole** — `validate --check=hardcoded-strings`, `…=coverage`,
  `…=quality`, `…=acceptance`. Każda kontrola to osobny krok (GitHub) albo job (GitLab), bo
  platforma pokazuje w pull requeście nazwę tego, który padł: `BabelHub / coverage` na czerwono
  mówi, co blokuje merge, bez czytania logu;
* na **gałęzi głównej**: `sync` (push → pull) i commit zwrotny z tłumaczeniami oraz
  `.babelhub.lock.json`.

Bramką są `coverage` i `quality` (`--fail-on=error`); `hardcoded-strings` i `acceptance` mają wagę
ostrzeżenia, więc domyślnie **raportują, ale nie blokują** — kto chce twardej bramki, dopisuje
`--fail-on=warning`. Zestaw można też zadeklarować w repozytorium (`checks.run` w `.babelhub.yml`)
i wołać jeden `babelhub validate`; wtedy w checkach widać tylko „validate”, ale leci jedno żądanie
na endpoint zamiast jednego na kontrolę.

Instrukcja krok po kroku, lista sekretów i wyjaśnienie kodów wyjścia: `docs/ci.md`.
