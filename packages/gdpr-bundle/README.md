# Opus125 GdprBundle

[![Latest Stable Version](https://poser.pugx.org/opus125/gdpr-bundle/v/stable)](https://packagist.org/packages/opus125/gdpr-bundle)
[![License](https://poser.pugx.org/opus125/gdpr-bundle/license)](https://packagist.org/packages/opus125/gdpr-bundle)

Registry-driven GDPR tooling for Symfony: declare your personal data once, and
subject access, portability, erasure and the records of processing fall out as
queries over that declaration.

> **Note**
> This repository is a **read-only mirror**, split automatically from the
> [`opus-125/core`](https://github.com/opus-125/core) monorepo. Open issues and
> pull requests against the monorepo, not here.

> **Scope honesty.** This bundle gives you the *technical tools* to implement
> subject rights and accountability duties. It does not make an application
> "GDPR-compliant": assessing legal bases, notification processes and concrete
> retention periods stays the controller's responsibility.

## The idea

Almost every GDPR duty hangs on one question: *which data belongs to which
person, and what may/must happen to it, when?* Answer that **once**, declaratively,
and the individual rights become read operations over it instead of bespoke code.

So the core is not a pile of features but a **Personal-Data-Registry** — a map of
the personal fields, built from three attributes shared with the rest of the
family via [`opus125/data-contracts`](https://packagist.org/packages/opus125/data-contracts):

```php
use Opus125\DataContracts\Attribute as Gdpr;
use Opus125\DataContracts\Erasure\ErasureStrategy;

#[Gdpr\DataSubject]                                   // this entity IS a person
class Contact
{
    #[Gdpr\PersonalData(category: 'name', purpose: 'crm', basis: 'contract', erasure: ErasureStrategy::Pseudonymize)]
    private string $name;

    #[Gdpr\PersonalData(category: 'health', purpose: 'support', basis: 'consent', sensitive: true, erasure: ErasureStrategy::CryptoShred)]
    private ?string $note;                            // Art. 9 special category
}

class Order
{
    #[Gdpr\SubjectLink(Contact::class)]               // points at the person
    private Contact $contact;

    #[Gdpr\PersonalData(category: 'address', purpose: 'shipping', basis: 'contract', erasure: ErasureStrategy::Nullify)]
    private string $shippingAddress;
}
```

## Installation

```bash
composer require opus125/gdpr-bundle
```

With Symfony Flex the bundle is enabled automatically; otherwise add
`Opus125\GdprBundle\Opus125GdprBundle` to `config/bundles.php`. With DoctrineBundle
installed there is nothing else to configure — the `gdpr_subject_key` entity is
mapped for you and the key-wrapping secret comes from `APP_SECRET`. Create the
table with your usual migrations/schema tool.

## Subject access & portability (Art. 15 / 20)

Registry-driven, so it is always complete — every `#[PersonalData]` field across
every entity that links to the subject, with each field's metadata:

```php
$report = $access->collect(new SubjectReference(Contact::class, $id));
$json   = $jsonExporter->export($report);   // grouped, with purpose/basis/category
$csv    = $csvExporter->export($report);    // one row per field
```

```bash
php bin/console gdpr:export 'App\Entity\Contact' 42 --format=json -o contact-42.json
```

## Erasure & anonymisation (Art. 17)

"Erasure" is **three** mechanisms, declared per field — because active operational
data and immutable history need different treatment:

| Strategy | For | Effect |
|----------|-----|--------|
| `nullify` | live fields with no retention need | the value is cleared |
| `pseudonymize` | live fields kept for statistics | replaced by a **stable** pseudonym (references & aggregates survive, the person doesn't) |
| `crypto_shred` | **append-only** stores (audit, revision) | the subject's data key is destroyed → ciphertext stays (chain intact), plaintext is gone forever |

```bash
php bin/console gdpr:erase 'App\Entity\Contact' 42 --force
```

- **Legal hold (Art. 17 (3))** refuses erasure and records the reason — implement
  `LegalHoldInterface`.
- **Shared data** (a record reachable from another subject) is left untouched, not
  stripped from someone who did not ask (conservative reference check).
- A field with **no** declared strategy is never touched.
- Every erasure dispatches a `SubjectErased` event so the act can be audited.

Crypto uses libsodium's XChaCha20-Poly1305 AEAD (never hand-rolled); each subject
gets a DEK wrapped under an `APP_SECRET`-derived KEK in the `KeyStoreInterface`
(DB default; Vault/KMS/HSM are extension points).

## Records of processing (Art. 30)

Because the fields carry `category` / `purpose` / `basis`, a processing register
is generated from the code instead of hand-maintained:

```bash
php bin/console gdpr:ropa:export --format=table
```

It covers only the *code-visible* processing — manual/paper/third-party processing
must be added by the controller. A scaffold, not a complete register.

## Optional Audit-bundle integration

If [`opus125/audit-bundle`](https://packagist.org/packages/opus125/audit-bundle)
is installed, the two share the personal-data vocabulary but **not** code. Alias
the audit key provider to the bridge this bundle ships:

```yaml
services:
    Opus125\AuditBundle\Crypto\SubjectKeyProviderInterface:
        '@Opus125\GdprBundle\Integration\Audit\AuditKeyProviderBridge'
```

Now crypto-shredding a subject also renders their `#[Audit\Sensitive]` trail
values unreadable, while the audit hash-chain stays valid. The wiring is explicit
on purpose — adopting GDPR never silently re-keys an existing audit trail. Without
the audit bundle, everything else works unchanged; without this bundle, the audit
bundle works unchanged.

## Scope

**v1 (now):** registry · subject resolution · access/portability · erasure
(three strategies) · records of processing.

**Deliberately later (demand-pull):** consent management (Art. 7) and an automated
retention scheduler (Art. 5 (1) e).

**Deliberately out of scope:** breach-notification workflow (human judgement),
legal-basis *assessment* (the bundle stores the declared basis, never evaluates
it), and fixing concrete retention periods (legal configuration).

## Documentation

See [`docs/index.md`](docs/index.md).

## License

Released under the [MIT License](LICENSE).
