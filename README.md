# BabelHub CLI

Synchronize translation keys and approved catalogs between your codebase and
[BabelHub](https://github.com/BabelHubEU/Backend), and run translation checks in CI.

- Scan PHP and Twig source files for translation keys.
- Push keys and source strings to BabelHub.
- Pull approved translations into PHP or JSON files, preserving local formatting and extra keys.
- Check translation quality, coverage, approval status and hardcoded strings.

## Installation

Requires PHP 8.3 or newer with the cURL extension, and access to a BabelHub instance.

Install globally with Composer:

```bash
composer global require babelhub/cli:~0.1.0
babelhub --version
```

Or add it to your project:

```bash
composer require --dev babelhub/cli:~0.1.0
vendor/bin/babelhub --version
```

A standalone `babelhub.phar` is distributed through [GitHub Releases](https://github.com/BabelHubEU/CLI/releases).
Run it with `php babelhub.phar` in place of `babelhub` in the examples below.

## Quick start

Create a project in your BabelHub instance, then run these commands from your application directory:

```bash
# Replace the URL with your BabelHub instance. Complete login in the browser.
babelhub login --api-url=https://babelhub.example.com

# Select the project, source language and translation file paths.
babelhub init

babelhub scan       # inspect keys found in the code
babelhub push       # upload keys and source strings
babelhub pull       # download approved translations
babelhub validate   # run translation checks
```

`babelhub sync` combines `push` and `pull`. For project-local installations, use
`vendor/bin/babelhub` instead of `babelhub`.

## Configuration and credentials

`babelhub init` creates `.babelhub.json`; YAML configuration is also supported with
`babelhub init --config=yaml`. Commit the configuration to your application's repository.
Keep only one configuration file: `.babelhub.json` or `.babelhub.yml`.

Credentials are stored separately from project configuration. For CI, provide the server URL
through `BABELHUB_API_URL` and a personal access token through the secret `BABELHUB_TOKEN`.
Never commit the token. See the [CI guide](docs/ci.md) for permissions and setup examples.

## Commands

| Command | Purpose |
| --- | --- |
| `login`, `logout`, `whoami` | Manage authentication and inspect the current identity |
| `init` | Configure a project |
| `scan` | Find translation keys in PHP and Twig |
| `push` | Upload keys and source strings |
| `pull` | Download approved translation catalogs |
| `sync` | Push, then pull |
| `status` | Show coverage, quality and readiness |
| `diff` | Show key changes since a point in time |
| `validate` | Run translation checks and return a nonzero exit code on failure |

Use `babelhub <command> --help` for options. `--output=json` provides machine-readable results;
diagnostics go to stderr. `validate` returns exit code `6` when a check fails.

## Documentation

- [CLI manual](docs/cli.md): configuration, commands, file formats and exit codes.
- [CI guide](docs/ci.md) and [workflow examples](examples/ci/): GitHub Actions and GitLab CI.
- [Changelog](CHANGELOG.md): changes in each release.
- [Contributing](CONTRIBUTING.md): local development, tests and PHAR builds.

CLI messages and the detailed manual are currently in Polish.

## License

[MIT](LICENSE).
