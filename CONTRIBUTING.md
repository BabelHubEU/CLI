# Contributing

## Local development

Use PHP 8.3 or newer with cURL and Composer. Install development dependencies and run the checks:

```bash
composer install
composer check
```

`composer check` runs PHPUnit, PHPStan (level 8) and PHPCS (PSR-12).
Use `php bin/babelhub` to run the CLI directly from the checkout.

## Building the PHAR

```bash
composer phar
php babelhub.phar --version
```

The build requires `phar.readonly=0`, which the Composer script sets for the build process.
The generated `babelhub.phar` is ignored by Git. Build tests also run the archive outside
the checkout to check that it does not depend on local source paths.

## Compatibility

Keep command behavior consistent across operating systems. Machine-readable output uses `\n`
line endings. Tests for post-processing commands should use PHP rather than relying on a POSIX
shell. Normalize paths when matching files in the PHAR builder.

Record user-facing changes under `Unreleased` in [CHANGELOG.md](CHANGELOG.md), grouped by
`Added`, `Changed`, `Fixed`, `Removed` or `Security`. Explain what changes for the user;
include migration steps when existing configuration or behavior is affected.

For command behavior and configuration, see the [CLI manual](docs/cli.md).
For versioning, tags and publication, see the [maintainer release guide](docs/releasing.md).
