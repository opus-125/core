# Opus125 AuditBundle

[![Latest Stable Version](https://poser.pugx.org/opus125/audit-bundle/v/stable)](https://packagist.org/packages/opus125/audit-bundle)
[![License](https://poser.pugx.org/opus125/audit-bundle/license)](https://packagist.org/packages/opus125/audit-bundle)

Tamper-evident audit trail with GDPR-compliant crypto-shredding and retention
for Symfony applications — the shared compliance spine for public-sector
software.

> **Note**
> This repository is a **read-only mirror**. It is split automatically from the
> [`opus-125/core`](https://github.com/opus-125/core) monorepo. Please open issues
> and pull requests against the monorepo, not here.

## Installation

```bash
composer require opus125/audit-bundle
```

If you are not using Symfony Flex, enable the bundle manually:

```php
// config/bundles.php
return [
    // ...
    Opus125\AuditBundle\Opus125AuditBundle::class => ['all' => true],
];
```

## Documentation

See [`docs/index.md`](docs/index.md).

## License

Released under the [MIT License](LICENSE).
