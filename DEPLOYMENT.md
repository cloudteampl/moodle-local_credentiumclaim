# Deployment & Release Guide

This document is for maintainers of `local_credentiumclaim`.

## Building an installable package

```bash
./deploy.sh
```

This reads the version from `version.php` (the single source of truth), packages
the plugin into `dist/local_credentiumclaim-<release>.zip` with a single
top-level `credentiumclaim/` directory (required so Moodle installs it as
`local/credentiumclaim`), and writes `.sha256` and `.md5` checksums next to it.

Development-only files (`.git`, `.github`, `deploy.sh`, `docs/`, `.claude`,
`CLAUDE.md`, `DEPLOYMENT.md`, IDE and OS cruft) are excluded from the ZIP.

## Cutting a release

Releases are cut by pushing a tag; day-to-day commits never publish anything.

1. Update `version.php`:
   - bump `$plugin->version` (format `YYYYMMDDXX`, must increase);
   - bump `$plugin->release` (SemVer, e.g. `1.1.0`).
2. Update `CHANGELOG.md`.
3. Commit to `main`.
4. Tag and push:
   ```bash
   git tag v1.1.0      # the tag MUST equal $plugin->release
   git push origin v1.1.0
   ```
5. The **Build and Release** GitHub Actions workflow then:
   - verifies the tag matches `$plugin->release` (fails the build otherwise);
   - runs `deploy.sh`;
   - uploads the ZIP + checksums as build artifacts;
   - publishes a GitHub Release with the installable ZIP attached.

`workflow_dispatch` is also available to build artifacts without publishing.

## Continuous integration

`.github/workflows/ci.yml` runs `moodle-plugin-ci` on every push and pull request
against a matrix of Moodle 4.5 and 5.0 (with `local_credentium` installed as the
declared dependency). It runs PHP lint, Moodle code checker, PHPDoc checker,
Mustache lint, upgrade-savepoint checks, and PHPUnit.

## Version management

| Field | Format | Example |
|---|---|---|
| `$plugin->version` | `YYYYMMDDXX` integer | `2026070600` |
| `$plugin->release` | SemVer string | `1.0.0` |
| Git tag | `v` + release | `v1.0.0` |

The release workflow enforces `tag == v{$plugin->release}`.

## Pre-release checklist

- [ ] `moodle-plugin-ci` green on both Moodle versions.
- [ ] `version.php` version and release bumped consistently.
- [ ] `CHANGELOG.md` updated.
- [ ] No secrets committed; logs verified secret-free.
