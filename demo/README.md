# Opus Demo Application

A minimal Symfony 8 application that wires up the Opus bundles from this monorepo
so they can be exercised end-to-end during development.

The bundles are consumed through a Composer [path repository](https://getcomposer.org/doc/05-repositories.md#path),
so changes under `../packages/*` are picked up immediately — no publishing required.

## Prerequisites

- PHP 8.4+ with `pdo_pgsql` and `sodium`
- A PostgreSQL database (the `audit-bundle` targets PostgreSQL)

Point `DATABASE_URL` and `OPUS_AUDIT_KEK` in [`.env`](.env) at your setup. Generate
a master key with:

```bash
php -r "echo base64_encode(random_bytes(32)).PHP_EOL;"
```

## The audit demo

```bash
cd demo
composer install
php bin/console app:demo
```

`app:demo` recreates the schema and then walks through the Spine:

1. records three changes to an `Antrag` (application) as the clerk *Anna Berger*;
2. prints the decrypted audit trail;
3. verifies the hash-chain is intact;
4. crypto-shreds the applicant (GDPR Art. 17) — the sensitive note becomes
   `[redacted: erased]` while the non-personal metadata and the chain stay valid.

Then try the bundle's own commands:

```bash
php bin/console audit:verify                              # CI-friendly exit code
php bin/console audit:seal                                # checkpoint the heads
php bin/console audit:export --stream=antrag --format=json
php bin/console audit:purge --force                       # honours retention + holds
```

## How it is wired

Standard DoctrineBundle, nothing special: Doctrine is configured in
[`config/packages/doctrine.yaml`](config/packages/doctrine.yaml) and the audit
bundle in [`config/packages/opus_audit.yaml`](config/packages/opus_audit.yaml).
The bundle registers its own entity mappings and its `onFlush` listener
automatically, so there is no glue code — the demo's `src/` contains only the
domain entities and the `app:demo` command.
