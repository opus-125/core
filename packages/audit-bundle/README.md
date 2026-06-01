# Opus AuditBundle

[![Latest Stable Version](https://poser.pugx.org/opus/audit-bundle/v/stable)](https://packagist.org/packages/opus/audit-bundle)
[![License](https://poser.pugx.org/opus/audit-bundle/license)](https://packagist.org/packages/opus/audit-bundle)

Audit logging for Symfony applications.

> **Note**
> This repository is a **read-only mirror**. It is split automatically from the
> [`opus-125/core`](https://github.com/opus-125/core) monorepo. Please open issues
> and pull requests against the monorepo, not here.

## Installation

```bash
composer require opus/audit-bundle
```

If you are not using Symfony Flex, enable the bundle manually:

```php
// config/bundles.php
return [
    // ...
    Opus\AuditBundle\OpusAuditBundle::class => ['all' => true],
];
```

## Documentation

See [`docs/index.md`](docs/index.md).

## License

Released under the [MIT License](LICENSE).
