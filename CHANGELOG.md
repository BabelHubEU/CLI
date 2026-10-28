# Changelog

User-facing changes are recorded here for each release. Versions follow the `v<version>` Git tags.
This changelog starts with version 0.1.5; earlier releases predate it.

## [Unreleased]

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
