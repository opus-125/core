# Opus125 DataContracts

[![Latest Stable Version](https://poser.pugx.org/opus125/data-contracts/v/stable)](https://packagist.org/packages/opus125/data-contracts)
[![License](https://poser.pugx.org/opus125/data-contracts/license)](https://packagist.org/packages/opus125/data-contracts)

The shared **personal-data vocabulary** for the Opus125 bundle family: a tiny,
logic-free package of attributes and interfaces. No services, no Doctrine
binding — just the words two otherwise-independent bundles agree on.

> **Note**
> This repository is a **read-only mirror**, split automatically from the
> [`opus-125/core`](https://github.com/opus-125/core) monorepo. Open issues and
> pull requests against the monorepo, not here.

## Why a separate package

```
opus125/data-contracts        ← only attributes + interfaces
   ↑                    ↑
opus125/gdpr-bundle   opus125/audit-bundle   ← depend on contracts, NOT on each other
```

The GDPR bundle *acts on* these attributes (access, erasure, records of
processing); the Audit bundle may *read* them (to mask sensitive fields). Sharing
the vocabulary — and nothing else — lets each bundle be installed and versioned
on its own. This is the idiomatic Symfony `*-contracts` pattern.

Because it is the contract, the attribute signatures and the `ErasureStrategy`
backing values are **BC-critical** and follow strict SemVer.

## Contents

| Symbol | Kind | Purpose |
|--------|------|---------|
| `#[PersonalData(category, purpose?, basis?, sensitive?, erasure?)]` | attribute | Mark a field as personal data |
| `#[DataSubject]` | attribute | Mark an entity as a natural person (root of belonging) |
| `#[SubjectLink(target)]` | attribute | Link personal data on another entity to its subject |
| `ErasureStrategy` | enum | `nullify` · `pseudonymize` · `crypto_shred` |
| `SubjectReference` | value object | `Class#id` handle to one subject |
| `SubjectResolverInterface` | interface | Navigate any record → its subject(s) |

```php
use Opus125\DataContracts\Attribute as Gdpr;
use Opus125\DataContracts\Erasure\ErasureStrategy;

#[Gdpr\DataSubject]
class Contact
{
    #[Gdpr\PersonalData(category: 'name', purpose: 'crm', basis: 'contract', erasure: ErasureStrategy::Pseudonymize)]
    private string $name;

    #[Gdpr\PersonalData(category: 'health', purpose: 'support', basis: 'consent', sensitive: true)]
    private ?string $note;
}

class Order
{
    #[Gdpr\SubjectLink(Contact::class)]
    private Contact $contact;

    #[Gdpr\PersonalData(category: 'address', purpose: 'shipping', basis: 'contract')]
    private string $shippingAddress;
}
```

## License

Released under the [MIT License](LICENSE).
