# BabelHub CLI

`babelhub` keeps a project's translation catalog in sync with the code that uses it — and gates
a pull request when a translation would break the app:

```bash
babelhub scan       # which keys does the code ask for
babelhub push       # the code → service: one request for the whole scan, source strings as APPROVED
babelhub pull       # the service → files on disk: atomic write, keeps keys the catalog does not know
babelhub validate   # the CI gate: exit 0 or 6, four named checks
babelhub sync       # push, then pull
```

It is the client side of **BabelHub**, a translation management service that stores keys and
per-language values with an approval status, versions the approved catalog per (project, language)
and serves it over `/api/v1`. The CLI never touches a database: it reads a project configuration,
a credential and the API.

## Install

The package is **not on Packagist yet** (see *Status*), so today the honest options are:

**As a PHAR — one file, no Composer.** Build it from this repository and copy the result into your
pipeline, or take it from a release asset once releases exist:

```bash
composer install
php -d phar.readonly=0 build-phar.php        # → babelhub.phar
php babelhub.phar --version
```

**Into a project** (as a dev dependency), until publication — add this repository as a VCS
repository and require `babelhub/cli:^0.1`:

```bash
composer config repositories.babelhub vcs https://gitlab.com/babelhub/cli.git
composer require --dev babelhub/cli:^0.1
vendor/bin/babelhub --version
```

**Globally**, after publication: `composer global require babelhub/cli`.

## Credential

The configuration file and the credential are **separate on purpose**: the configuration lives in
the repository and is reviewed like code, the credential never does.

```bash
babelhub login                       # pairing in the browser; without a TTY: JSON + exit 7,
                                     # finish with `babelhub login --complete=<browser_url>`
babelhub login --token=pat_…         # CI and service accounts
```

Source order: `--token` → `BABELHUB_TOKEN` → `~/.config/babelhub/credentials.json` (written by
`login`, mode `0600`). There is no option that puts a secret into `.babelhub.yml`.

## Configuration

`.babelhub.yml` (or `.babelhub.json` — **two files at once is an error**, exit `2`) in the
project root:

```yaml
project: learnhub
source_locale: en_GB

files:
  pattern: "translations/messages.{lang}.php"   # `{lang}` and `{ns}` are the closed set
  format: php

scan:
  paths: [src, templates]

upload:
  mark_missing_as_removed: false   # never silently retire a key somebody added in the panel
  unapprove_changed: false         # a source change is a fact from the code; approval is a human decision

download:
  locales: []                      # empty = every language the project runs
  sort: file                       # keep the order the file already has, so diffs stay small
  post_process_command: "vendor/bin/prettier --write translations"   # 300 s limit, exit 124 on timeout

checks:
  fail_on: error                   # `warning` makes warnings block the merge
  run: [quality, coverage, acceptance, hardcoded-strings]
```

Missing `checks.run` is an error, not a default: a gate that always passes is worse than no gate.

## Exit codes

The table is a product contract, covered by `tests/Cli/CommandContractTest.php` across commands.

| Code | Meaning |
| --- | --- |
| `0` | done — and only done (partial success is **not** `0`) |
| `1` | local failure, or the service answered `5xx` |
| `2` | usage error or broken configuration (`CliConfigException`) |
| `3` | authentication rejected |
| `4` | rate limited |
| `5` | the service rejected the request |
| `6` | a check failed — `validate` is the gate |
| `7` | pairing is waiting for a human |

`stdout` carries the result, `stderr` carries diagnostics. `--output=text|plain|json`: `plain` is
one line per item (for `grep`/`awk`), `json` is exactly one document.

## Commands

| Command | What it does |
| --- | --- |
| `login` / `logout` / `whoami` | credential of a **human** (personal access token), never of the application |
| `init` | writes `.babelhub.yml`/`.json` — validated against the service before saving |
| `scan` | finds keys in PHP and Twig (`path:line`); dynamic keys are skipped on purpose |
| `push` | the whole code state in one request; marks missing keys as `removed` only for `origin = scan` |
| `pull` | writes catalogs back in the style of the file that is already there |
| `sync` | `push`, then `pull` |
| `status` | coverage, keys, QA, readiness — always exit `0`, it is a read |
| `diff` | what changed in the keys since a moment (default: the last `pull`) |
| `validate` | the gate: named checks, `--fail-on=error|warning`, `--check=…`, `--locale=…` |

Full manual: [`docs/cli.md`](docs/cli.md) (currently in Polish — see *Status*). CI setup, secrets
and what each check needs: [`docs/ci.md`](docs/ci.md) and the ready-made fragments in
[`examples/ci/`](examples/ci/).

## Development

```bash
composer install
composer check      # PHPUnit + PHPStan (level 8) + PHPCS (PSR-12)
composer phar       # build the PHAR
```

The gate runs on Linux **and on Windows** (`php: ^8.3`), and that promise has a cost behind it:
the result lines of `--output=plain|json` always end with `\n` (never `PHP_EOL`), the hook
fixtures use `php -r` instead of a POSIX shell, and the PHAR builder normalises paths before
matching them. Every one of those came out of running this gate on Windows *after* it was green
in a Linux container — where none of them is visible.

The version guard deserves a sentence: `src/Version.php` holds the version reported by
`--version`, and `tests/Cli/VersionTest.php` compares it with the **git tag on HEAD** — a tagged
commit must match the tag, an untagged one must carry a `-dev` suffix. The manifest has no
`version` field on purpose (Composer and Packagist derive it from the tag), so the tag is the
reference. That test is what makes `--version`, which other tools in this category get wrong,
a checked promise rather than a comment.

Release process: push a tag `vX.Y.Z` → the `release` job in `.gitlab-ci.yml` runs the full gate on
the tagged commit, builds `babelhub.phar` and attaches it to the GitLab release. Note what the
gate enforces at that moment: `VersionTest` requires `src/Version.php` to **equal the tag**, so the
first release needs the constant bumped from `0.1.0-dev` to `0.1.0` — a pipeline that refuses to
publish a binary lying about itself is the whole point of that test. Registering the repository
with Packagist is a one-time manual step; after that, tags publish themselves.

## Status

This repository was split out of the BabelHub monorepo (`cli/` + `bin/babelhub` + the CLI test
suite + the CI fragments, Fala 1 item 1.11) and lives at <https://gitlab.com/babelhub/cli>.
What is still open:

* **publication (Packagist)** — until then the CI fragments install from VCS:
  `composer global config repositories.babelhub vcs https://gitlab.com/babelhub/cli.git`;
* **language of the messages** — the CLI still speaks Polish (the manual too); the plan tracks
  the English catalogues as an open item, and this is the channel that reaches teams abroad.

## Licence

MIT — declared in `composer.json`; the `LICENSE` file is one of the open decisions above.
