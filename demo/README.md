# Opus Demo Application

A minimal Symfony 8 application that wires up the Opus bundles from this monorepo
so they can be exercised end-to-end during development.

The bundles are consumed through a Composer [path repository](https://getcomposer.org/doc/05-repositories.md#path),
so changes under `../packages/*` are picked up immediately — no publishing required.

## Run it

```bash
cd demo
composer install
php -S localhost:8000 -t public
# or, with the Symfony CLI:
symfony serve
```

Verify the bundle is loaded:

```bash
php bin/console debug:container --tag=kernel.bundle | grep Audit
php bin/console config:dump-reference OpusAuditBundle
```
