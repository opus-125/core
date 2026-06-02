# Opus125

Development monorepo for the **Opus125** family of Symfony 8 bundles. Each bundle is
developed, documented and tested here, exercised by a shared demo application, and
automatically split into its own **read-only** repository for distribution on
[Packagist](https://packagist.org).

## Requirements

- PHP **8.4+**
- Symfony **8.x**
- Composer 2

## Layout

```
packages/            One directory per package (each a standalone Composer package)
  data-contracts/    opus125/data-contracts → shared personal-data attributes + interfaces
  audit-bundle/      opus125/audit-bundle → tamper-evident audit trail
  gdpr-bundle/       opus125/gdpr-bundle → registry-driven GDPR tooling (depends on data-contracts)
demo/                Symfony 8 app integrating the bundles via a path repository
docs/                Documentation site — a Vite + React app (deployed to GitHub Pages)
.github/workflows/
  ci.yml             Tests, PHPStan, coding standards, monorepo validation
  split.yml          Read-only split of each package to its own repo
  sync-docs.yml      Mirror docs/ to the opus-125.github.io Pages repo
```

The documentation lives at **<https://opus-125.github.io>**. It is a Vite + React
site (the same stack as [Reactolith](https://github.com/reactolith/reactolith)) and
is mirrored on every push into the [`opus-125/opus-125.github.io`](https://github.com/opus-125/opus-125.github.io)
repository, which builds and deploys it via native GitHub Pages.

```bash
cd docs && npm install && npm run dev   # work on the docs locally
```

## Local development

```bash
composer install          # installs dev tooling + all package deps (merged)
composer check            # monorepo validate + cs + phpstan + tests
```

Individual steps are available as `composer test`, `composer phpstan`,
`composer cs` / `composer cs:fix`.

### Working in the demo app

```bash
cd demo && composer install
php bin/console debug:container --tag=kernel.bundle
```

The demo consumes the bundles through a Composer path repository, so edits under
`packages/*` are picked up without publishing.

## Adding a new bundle

1. `mkdir -p packages/<name>-bundle/{src,config,docs,tests}` and add a
   `composer.json` (`type: symfony-bundle`, vendor `opus125/`).
2. Add its PSR-4 namespaces to the root [`composer.json`](composer.json)
   (`autoload`, `autoload-dev`) and to `replace`.
3. Run `composer monorepo:merge` to sync dependencies into the root manifest,
   then `composer monorepo:validate`.
4. Create the target repository `opus-125/<name>-bundle` on GitHub.
5. Add a matrix entry to [`.github/workflows/split.yml`](.github/workflows/split.yml).
6. Register the new repo on Packagist (see below).

## Release & distribution flow

```
       monorepo (this repo, opus-125/core)
                     │  push to main / push tag vX.Y.Z
                     ▼
        .github/workflows/split.yml
                     │  danharrin/monorepo-split-github-action
                     ▼
   opus-125/audit-bundle  (read-only mirror, incl. tags)
                     │  Packagist GitHub webhook
                     ▼
      Packagist: opus125/audit-bundle  →  composer require
```

### One-time setup

1. **`ACCESS_TOKEN` secret** — a token with `repo` scope (or fine-grained
   `contents: write` + `administration: write` on the org), stored as a repository
   secret named `ACCESS_TOKEN` in `opus-125/core`. The split workflow uses it to
   **auto-create** each target mirror repo on first run and to push into it — no
   manual repo creation needed.
2. **`DOCS_SYNC_TOKEN` secret** — a token with `contents: write` on
   `opus-125/opus-125.github.io`, stored as a repository secret named
   `DOCS_SYNC_TOKEN`. The `sync-docs` workflow uses it to mirror `docs/` there.
3. **GitHub Pages** — the `opus-125/opus-125.github.io` repo serves the docs at
   <https://opus-125.github.io> via the `Deploy docs to GitHub Pages` workflow
   (Pages source = "GitHub Actions").
4. **Packagist** — submit each target repo URL once at
   <https://packagist.org/packages/submit>, then enable the GitHub service hook /
   "Auto-Update" so new tags publish automatically.

### Cutting a release

```bash
# Bumps versions across all packages and creates a git tag.
vendor/bin/monorepo-builder release v0.1.0 --dry-run   # preview
vendor/bin/monorepo-builder release v0.1.0             # for real
git push origin main --tags
```

Pushing the tag triggers `split.yml`, which mirrors the tag into each bundle repo;
Packagist then publishes the new version.

## License

[MIT](LICENSE).
