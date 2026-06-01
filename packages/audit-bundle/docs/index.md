# Opus AuditBundle

Audit logging for Symfony with Doctrine: it records who changed what, keeps the
trail tamper-evident, and can encrypt personal fields so they can be erased on
request (GDPR Art. 17) while the rest of the record is kept.

- **Symfony 7/8 · PHP 8.3+ · Doctrine ORM 3** — works on any Doctrine DBAL
  platform.
- You declare what to audit with attributes; configuration is infrastructure
  only and everything has safe defaults.
- Without an attribute, nothing is recorded.

## What it does

- **Records changes.** A Doctrine `onFlush` listener writes a normal
  `AuditEntry` entity for every create/update/delete of an `#[Auditable]`
  entity — including collection (ManyToMany/OneToMany) changes. The entry is
  written in the same flush as your change, so it commits or rolls back with it.
- **Tamper-evidence.** Each entry stores the hash of its predecessor in the
  stream, so altering or dropping a past entry is detectable
  (`AuditEntryRepository::verify()`).
- **Encrypts sensitive fields.** `#[Sensitive]` values are stored encrypted,
  keyed per data subject. Erasing a subject makes those values unreadable
  (`[redacted: erased]`) while keeping the non-personal record and the chain
  intact.
- **Retention.** `#[Retention('10 years')]` lets you purge entries past their
  keep duration (`audit:purge --force`).

## Installation

```bash
composer require opus/audit-bundle
```

With Symfony Flex the bundle is enabled automatically; otherwise add it to
`config/bundles.php`. With DoctrineBundle installed, the audit entity mapping and
the listener are registered for you — no extra wiring. The crypto-shredding key
is derived from `APP_SECRET` by default.

```yaml
# config/packages/opus_audit.yaml (optional — these are the defaults)
opus_audit:
    entry_class: Opus\AuditBundle\Model\AuditEntry
    retention:
        default: null   # null = keep forever; per class via #[Retention]
```

Create the `audit_entry` and `audit_shredded_subject` tables with your usual
migrations/schema tool.

## Usage

```php
use Opus\AuditBundle\Attribute as Audit;

#[Audit\Auditable]                 // record this entity (stream defaults to the class)
#[Audit\Retention('10 years')]
class Invoice
{
    #[Audit\AuditIgnore]           // never logged
    private string $internalToken;

    #[Audit\Sensitive]             // encrypted + erasable
    private string $customerName;

    #[Audit\DataSubject]           // whose key protects the sensitive data
    private Customer $customer;

    private string $status;
}
```

Mutate normally — no special transaction needed:

```php
$invoice->setStatus('open');
$em->flush();   // the audit entry is written in the same flush
```

Read the trail (and decrypt for display/export) via the repository and the
Serializer:

```php
$entries = $em->getRepository(AuditEntry::class)->findForTarget(Invoice::class, $id);
$json = $serializer->serialize($entries, 'json'); // sensitive values decrypted, shredded ones redacted
```

Verify integrity (e.g. from your own console command or a health check):

```php
$result = $em->getRepository(AuditEntry::class)->verify('App\\Entity\\Invoice');
if (!$result->valid) { /* tampering at $result->brokenAtSequence */ }
```

Erase a person (GDPR Art. 17):

```php
$subjectKeyProvider->shred($subjectId); // their encrypted values become unreadable
```

## Attributes

| Attribute | Effect | Default |
|-----------|--------|---------|
| `#[Auditable(stream?)]` | Entity is audited | not audited |
| `#[AuditIgnore]` | Field never logged | logged |
| `#[Sensitive]` | Field encrypted + erasable | plaintext |
| `#[DataSubject]` | Marks the subject for erasure | resolver heuristic |
| `#[Retention('…')]` | Keep duration | keep forever |

A field-name deny heuristic (`password`, `token`, `secret`, `*_key`, …) masks
suspicious values even without `#[AuditIgnore]`.

## Customising

Each seam is an interface you can replace by aliasing it to your own service:

| Interface | Responsibility | Default |
|-----------|----------------|---------|
| `AuditEntryInterface` | The audit entity | `AuditEntry` (set `entry_class`) |
| `ActorInterface` | Who acted | implement it on your `User`, or the `Actor` value object |
| `ActorResolverInterface` | Resolve the current actor | security token / `runAs` |
| `SubjectResolverInterface` | Resolve data subjects | `#[DataSubject]` + heuristic |
| `SubjectKeyProviderInterface` | Per-subject encryption keys | derived from `APP_SECRET` |
| `RetentionPolicyInterface` | Retention per class | `#[Retention]` |

## Good to know

- **Bulk DQL/native `UPDATE`/`DELETE`** bypass Doctrine's UnitOfWork and are not
  captured — the listener is detective, not a guarantee against bulk operations.
- **Tamper-evidence** detects modification of the stored log; it is not a defence
  against someone who controls the database fabricating a fresh entry. Harden
  with restricted DB grants if you need more.
- The default key provider derives keys from `APP_SECRET`; erasure is enforced by
  refusing to re-derive a shredded subject's key. For erasure that holds even
  against someone with `APP_SECRET`, implement `SubjectKeyProviderInterface` with
  stored random keys that you physically delete.

Released under the [MIT License](../LICENSE).
