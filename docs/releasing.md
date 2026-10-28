# Maintainer release guide

The version reported by the CLI comes from `Version::VALUE` in `src/Version.php`.
Composer and Packagist derive package versions from Git tags; do not add a `version` field
to `composer.json`.

`tests/Cli/VersionTest.php` enforces two rules:

- A tagged release commit must report the version in its tag.
- An untagged commit must report a development version, such as `0.1.5-dev`.

## Release sequence

1. Move user-facing changes from `Unreleased` into a `## [<version>] - YYYY-MM-DD` entry
   in `CHANGELOG.md`. Use `Added`, `Changed`, `Fixed`, `Removed` or `Security` sections as needed.
   Describe the effect on users, including compatibility and migration steps; do not paste a Git log.
   Keep an empty `Unreleased` heading for the next cycle.
2. Set `Version::VALUE` to the release version without the `-dev` suffix.
3. Commit the change and create an annotated `v<version>` tag on that exact commit.
4. Run `composer check` on the tagged commit, then push the branch and tag to GitHub.
5. The `release` job in `.github/workflows/ci.yml` publishes a GitHub Release with
   `babelhub.phar` after the required checks and PHAR build pass. The release body is extracted from
   `CHANGELOG.md` by `php bin/release-notes.php v<version>`. A missing or duplicate entry,
   invalid date, empty body, placeholder or version mismatch blocks publication.
6. Immediately bump `Version::VALUE` to the next development version, commit, run
   `composer check` and push.

Run the version checks after committing and tagging the relevant step. An uncommitted
version bump is compared with the tag on the previous HEAD, which can correctly fail the guard.

## Package hosting

The package name is `babelhub/cli`. When changing repository hosting, update the source repository
and automatic update integration in Packagist. GitHub Releases and Packagist are separate
publication paths; verify both when releasing after the move.

Consumer CI examples in `examples/ci/` support both GitHub Actions and GitLab CI. They are
independent of the workflow used to build and release this repository.
