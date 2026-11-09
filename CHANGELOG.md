# Changelog

User-facing changes are recorded here for each release. Versions follow the `v<version>` Git tags.
This changelog starts with version 0.1.5; earlier releases predate it.

## [Unreleased]

## [0.1.8] - 2026-11-09

### Added

- `babelhub content:push` sends emails, push, SMS and marketing copy from `content/` — one
  directory per item with `meta.json`, `source.html` and `<locale>.html`. One request per item,
  so a single bad template (an undeclared variable) is reported on its own instead of sinking
  the batch; `--dry-run` reads the files and sends nothing.
- `babelhub content:pull` writes the same layout back from the service and leaves files it did
  not create untouched. It does not carry the approval status: files hold content, status is a
  fact about the work in the service.

### Fixed

- The dependency lock resolves for PHP 8.3, the minimum supported runtime, after a lock built
  on a newer PHP broke `composer install` on the oldest CI leg.
- `symfony/yaml` and `symfony/console` are past the YAML parser ReDoS advisory; the CLI parses
  `.babelhub.yml` from a cloned repository, so the parser is reachable from untrusted input.

## [0.1.7] - 2026-11-08

### Added

- `babelhub init` reports `cli_installed` and `init` to the funnel through `POST /api/v1/events`,
  after the configuration is written and with the user's own token. An install without a
  credential reports nothing, and a failed report never changes the exit code.

### Fixed

- `init` no longer repeats the `--config` validation and file-name selection three times in a
  row. Behaviour is unchanged; the dead reassignments are gone.

## [0.1.6] - 2026-11-08

### Added

- `babelhub init` without a credential starts a trial project: it calls `POST /api/v1/trial`,
  saves the returned token and continues with the project and source language the server
  created. The trial lasts seven days and is limited to 200 keys and two languages.
- `--target-locale` chooses the trial's target language (default `pl_PL`); `--source-locale`
  chooses the source.
- Expired and over-limit trials print an actionable hint with the `/trial/claim` address
  instead of the raw server message, so the next step is "create an account", not "retry".

### Changed

- The CLI manual points at the product documentation (`/docs`, `/docs/cli`) as the canonical
  user reference and keeps only the details those pages deliberately leave out.

## [0.1.5] - 2026-10-28

### Fixed

- Bind catalog locks to the server, organization and project, so switching environments or
  projects cannot reuse a catalog version from a different source.
- Verify local translation content before accepting an unchanged server catalog. `pull --check`
  now reports missing or edited managed translations, and `pull` restores them when needed.
- Include namespace files, configured root keys and post-processing results in local catalog
  verification while preserving additional local keys.

### Changed

- Move source hosting, CI and PHAR publication to GitHub. Consumer CI examples continue to
  support both GitHub Actions and GitLab CI.
- Simplify the public README and provide separate contributor and maintainer guides.

### Added

- Generate GitHub Release notes from this changelog and reject release publication when the
  version entry, date or release notes are missing.
