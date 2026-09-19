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

Three ways, in the order most people want them:

**With Composer** — into a project, or globally:

```bash
composer require --dev babelhub/cli:~0.1.0    # project
vendor/bin/babelhub --version

composer global require babelhub/cli:~0.1.0   # global
```

**Write `~0.1.0`, not `^0.1`.** Composer narrows a caret on a `0.x` version to an exact one:
`composer require babelhub/cli:^0.1` records `0.1` in `composer.json` and installs **`0.1.0`** —
the release that still had the Windows hook-timeout defect — while `~0.1.0` resolves to the newest
`0.1.x`. Measured twice with a clean cache, against Packagist and against the VCS repository:
the narrowing belongs to Composer, not to the source of the package.

**As a PHAR — one file, no Composer.** Download the asset from a [release][releases] (any file name
works — the binary addresses itself by the archive alias), or build it from a checkout:

```bash
composer install
php -d phar.readonly=0 build-phar.php        # → babelhub.phar
php babelhub.phar --version
```

[releases]: https://gitlab.com/babelhub/cli/-/releases

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

### Releasing

The version lives in one constant and the gate enforces it against the tag, so the order matters:

1. set `Version::VALUE` in `src/Version.php` to the release number (`0.1.1`, **no suffix**);
2. commit it and tag **that exact commit**: `git tag -a v0.1.1 -m "BabelHub CLI 0.1.1"`,
   then `git push origin main v0.1.1`;
3. the tag pipeline runs the full gate, builds `babelhub.phar` and attaches it to the release
   (job `release` in `.gitlab-ci.yml`);
4. **bump to the next development version right away** (`0.1.2-dev`) and push.

Skipping step 4 leaves `main` red on the next commit, and deliberately so: `tests/Cli/VersionTest.php`
requires a tagged commit to match its tag and an untagged one to carry a `-dev` suffix. A pipeline
that refuses to publish a binary lying about itself is the point of that test, not an obstacle —
and the released asset is checked the same way: `php babelhub.phar --version` on `v0.1.0` answers
`BabelHub CLI 0.1.0`.

**Run the gate after committing each step, not before.** The version test compares the constant
with the tag on **HEAD**, so a bump that is still uncommitted is measured against the previous
commit — and a bump on a tagged commit fails by design (`v0.1.1` on HEAD while the file already
says `0.1.2-dev` is exactly the lying binary the guard refuses). That is how a red gate during
a release usually looks, and it is not a defect.

Registering the repository with Packagist is a one-time manual step; after that, tags publish
themselves.

## Status

This repository was split out of the BabelHub monorepo (`cli/` + `bin/babelhub` + the CLI test
suite + the CI fragments, Fala 1 item 1.11) and lives at <https://gitlab.com/babelhub/cli>. It is
**public**, published on [Packagist][packagist] and carries tagged releases with the PHAR attached,
so `composer require babelhub/cli:~0.1.0` works without any credential — as do the CI fragments we
ship, which no longer configure a VCS repository. What is still open:

[packagist]: https://packagist.org/packages/babelhub/cli

* **language of the messages** — the CLI still speaks Polish (the manual too); the plan tracks
  the English catalogues as an open item, and this is the channel that reaches teams abroad.

## Licence

MIT — declared in `composer.json` and shipped as the `LICENSE` file (Copyright © 2026 Mikołaj Panecki).
