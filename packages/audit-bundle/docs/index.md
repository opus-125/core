# Opus125 AuditBundle

Audit logging for Symfony with Doctrine: it records who changed what, keeps the
trail tamper-evident, and can encrypt personal fields so they can be erased on
request (GDPR Art. 17) while the rest of the record is kept.

- **Symfony 7/8 · PHP 8.3+ · Doctrine ORM 3** — works on any Doctrine DBAL
  platform.
- Zero configuration to start; everything is customised through **interfaces**,
  **events** and a **custom entity** — no bundle config keys.
- Without an attribute, nothing is recorded.

## How it works

- A Doctrine `onFlush` listener writes a normal `AuditEntry` entity for every
  create/update/delete of an `#[Auditable]` entity (including collection
  changes), in the same flush as your change.
- Each entry chains onto its predecessor's hash, so altering or dropping a past
  entry is detectable (`AuditEntryRepository::verify()`).
- `#[Sensitive]` values are stored encrypted with a key supplied by a service.
  When that service returns `null` for a subject (you erased it), the value
  reads back as `[redacted: erased]` while the record and chain stay intact.
- `#[Retention('10 years')]` lets you purge entries past their keep duration
  (`audit:purge --force`).

## Installation

```bash
composer require opus125/audit-bundle
```

With Symfony Flex the bundle is enabled automatically; otherwise add it to
`config/bundles.php`. With DoctrineBundle installed there is **nothing else to
configure**: the bundle maps its `AuditEntry` entity, resolves
`AuditEntryInterface` to it, and registers the listener. The encryption key is
derived from `APP_SECRET` by default.

Create the `audit_entry` table with your usual migrations/schema tool.

## Usage

```php
use Opus125\AuditBundle\Attribute as Audit;

#[Audit\Auditable]                 // record this entity (stream defaults to the class)
#[Audit\Retention('10 years')]
class Invoice
{
    #[Audit\AuditIgnore]           // never logged
    private string $internalToken;

    #[Audit\Sensitive]             // encrypted + erasable
    private string $customerName;

    private string $status;
}
```

Mutate normally — no special transaction required:

```php
$invoice->setStatus('open');
$em->flush();   // the audit entry is written in the same flush
```

### Workflow transitions

If you drive a status field with the [Symfony Workflow component](https://symfony.com/doc/current/workflow.html),
add `#[AuditableWorkflow]` to opt the entity's transitions into the same trail —
no audit code in your workflow handlers:

```php
use Opus125\AuditBundle\Attribute as Audit;

#[Audit\Auditable(stream: 'article')]
#[Audit\AuditableWorkflow(marking: 'status')]   // 'status' is the workflow-marking field
class Article
{
    private string $status = 'draft';
}
```

```php
$workflow->apply($article, 'submit');
$em->flush();   // one `transition` entry written in the same flush/transaction
```

Each **applied** transition produces a single entry with `action = transition`:
the from/to places in `changes['place']`, the workflow and transition names in
`context`, the actor resolved as usual. Rejected guards and plain `can()` probes
are never recorded — only transitions that actually happened.

`marking:` names the field the workflow owns, so its change is logged once (as
the richer transition entry) instead of also as a `status` field update; a
direct edit to that field *without* a transition is still field-audited. Pass a
`transitions: [...]` allow-list to audit only some transitions. Alternatively,
flag a workflow `audited: true` in its metadata to track it without an entity
attribute. The feature activates only when `symfony/workflow` is installed.

Read the trail and decrypt for display/export via the repository and the
Serializer:

```php
$entries = $em->getRepository(AuditEntry::class)->findForTarget(Invoice::class, $id);
$json = $serializer->serialize($entries, 'json'); // sensitive values decrypted, erased ones redacted
```

Verify integrity:

```php
$result = $em->getRepository(AuditEntry::class)->verify('App\\Entity\\Invoice');
// $result->valid / $result->brokenAtSequence
```

## Attributes

| Attribute | Effect | Default |
|-----------|--------|---------|
| `#[Auditable(stream?)]` | Entity is audited | not audited |
| `#[AuditableWorkflow(marking?, transitions?)]` | Audit the entity's workflow transitions | not audited |
| `#[AuditIgnore]` | Field never logged | logged |
| `#[Sensitive]` | Field encrypted + erasable | plaintext |
| `#[Retention('…')]` | Keep duration | keep forever |

A field-name deny heuristic (`password`, `token`, `secret`, `*_key`, …) masks
suspicious values even without `#[AuditIgnore]`.

## Customising

Everything beyond the defaults is an interface you implement and alias, an event
you listen to, or your own entity — out of the box stays simple, complex cases
stay clean.

### Crypto-shredding: store the key where you want

The default derives the encryption key from `APP_SECRET` (so values are
encrypted but not erasable). To make data erasable, implement
`SubjectKeyProviderInterface` and return the key for an entity — or `null` once
it has been anonymised. A common pattern keeps the key on the `User`:

```php
final class UserKeyProvider implements SubjectKeyProviderInterface
{
    public function __construct(private EntityManagerInterface $em) {}

    public function keyFor(string $entityClass, string $entityId): ?string
    {
        $entity = $this->em->find($entityClass, $entityId);

        return $entity?->getAuditKey();   // null once the user is anonymised → shredded
    }
}
```

```yaml
services:
    Opus125\AuditBundle\Crypto\SubjectKeyProviderInterface: '@App\Audit\UserKeyProvider'
```

Erasure is then a normal domain operation (drop the stored key); the bundle
keeps no shredding state of its own.

### Your own entity

Map an entity that implements `AuditEntryInterface` (just `use AuditEntryTrait;`)
and point Doctrine at it — no bundle config:

```yaml
doctrine:
    orm:
        resolve_target_entities:
            Opus125\AuditBundle\Model\AuditEntryInterface: App\Entity\MyAuditEntry
```

### Events

`AuditEntryRecorded` is dispatched for each entry as it is added to the flush —
listen to it to mirror entries, enrich context, or record your own bookkeeping.

### Other seams

| Interface | Responsibility | Default |
|-----------|----------------|---------|
| `AuditEntryInterface` | The audit entity | `AuditEntry` (via `resolve_target_entities`) |
| `ActorInterface` | Who acted | implement on your `User`, or the `Actor` value object |
| `ActorResolverInterface` | Resolve the current actor | security token / `AuditContext::runAs()` |
| `SubjectKeyProviderInterface` | Per-subject encryption key | derived from `APP_SECRET` |
| `RetentionPolicyInterface` | Retention per class | `#[Retention]` |

## Good to know

- **Bulk DQL/native `UPDATE`/`DELETE`** bypass Doctrine's UnitOfWork and are not
  captured.
- Concurrency: `sequence_no` is `head + 1` per stream, guarded by a unique
  constraint — a rare concurrent collision fails (and is retried) rather than
  corrupting the chain, so no database lock is needed by default.
- Tamper-evidence detects modification of the stored log; it is not a defence
  against someone who controls the database. Restrict DB grants if you need more.

Released under the [MIT License](../LICENSE).
