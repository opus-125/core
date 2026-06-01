# Opus Demo Application

A minimal Symfony 8 application that wires up the Opus bundles from this monorepo
so they can be exercised end-to-end during development.

The bundles are consumed through a Composer [path repository](https://getcomposer.org/doc/05-repositories.md#path),
so changes under `../packages/*` are picked up immediately — no publishing required.

## Prerequisites

- PHP 8.4+ with `pdo_pgsql` and `sodium`
- A database supported by Doctrine (the demo uses PostgreSQL)

Point `DATABASE_URL` in [`.env`](.env) at your database. The audit bundle derives
its crypto-shredding key from `APP_SECRET`.

## Run the web demo

```bash
cd demo
composer install
php bin/console app:setup          # create schema + seed sample data
php -S 127.0.0.1:8000 -t public    # or: symfony serve
```

Open <http://127.0.0.1:8000/>:

- each application shows its **audit trail**;
- change the **status** or **note** and the trail grows (attributed to the
  clerk "Anna Berger");
- the note is `#[Sensitive]` — **Shred applicant** erases it (it shows
  `[redacted: erased]`) while the rest of the trail stays intact.

## How it is wired

Standard DoctrineBundle: Doctrine is configured in
[`config/packages/doctrine.yaml`](config/packages/doctrine.yaml) and the audit
bundle in [`config/packages/opus_audit.yaml`](config/packages/opus_audit.yaml).
The bundle registers its entity mapping and `onFlush` listener automatically, so
`src/` contains only the domain entities, a controller and a setup command.
