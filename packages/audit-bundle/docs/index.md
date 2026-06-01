# Opus AuditBundle

A tamper-evident **audit trail** with GDPR-compliant **crypto-shredding** and
**retention** for Symfony — the shared compliance spine for public-sector
software (AT/DE).

- **Symfony 7/8 · PHP 8.3+ · Doctrine ORM 3 · PostgreSQL**
- Behaviour is declared on your entities with **attributes**; YAML configures
  infrastructure only.
- Secure by default: without an attribute, nothing is recorded.

---

## Contents

- [Concept: one spine, opt-in escalations](#concept)
- [Installation](#installation)
- [Quick start](#quick-start)
- [The transaction requirement](#transactions)
- [How the audit trail works](#audit-trail)
- [Crypto-shredding & retention](#crypto-shredding)
- [Attribute reference](#attributes)
- [Configuration](#configuration)
- [Console commands](#commands)
- [Reading & exporting](#reading)
- [Extension points (stable contracts)](#contracts)
- [Deployment hardening](#hardening)
- [Honest limitations](#limitations)
- [Escalation tiers (roadmap)](#tiers)

---

<a id="concept"></a>
## Concept: one spine, opt-in escalations

Authority software is heterogeneous, so there is no single "secure enough"
setting. The bundle splits into:

- **The Spine** (mandatory, default-on): a reliable, tamper-*evident* change log
  (accountability) and a deletion/retention model that satisfies GDPR erasure
  **and** statutory archiving at the same time (crypto-shredding + retention).
- **Escalation tiers** (opt-in, independent): E1 versioning, E2 Merkle
  integrity, E3 authorship (qualified signatures / ID Austria), E4 trigger-level
  capture. You pay nothing for tiers you do not enable.

This package implements the **Spine** (v1).

<a id="installation"></a>
## Installation

```bash
composer require opus/audit-bundle
```

Enable the bundle (Symfony Flex does this automatically):

```php
// config/bundles.php
return [
    // ...
    Opus\AuditBundle\OpusAuditBundle::class => ['all' => true],
];
```

Provide a master key (KEK) for crypto-shredding — base64 of 32 random bytes:

```bash
php -r "echo base64_encode(random_bytes(32)).PHP_EOL;"
```

```yaml
# config/packages/opus_audit.yaml
opus_audit:
    kek: '%env(OPUS_AUDIT_KEK)%'
```

Then create the audit tables (`audit_entry`, `audit_event`, `audit_seal`,
`audit_crypto_key`) with your usual schema tool / migrations.

With **DoctrineBundle** installed (`^3.2` for Symfony 8) the bundle registers
its entity mappings and its `onFlush` listener automatically — no glue code.
Without DoctrineBundle it works against a hand-wired `EntityManager` too; just
make `Doctrine\ORM\EntityManagerInterface` and `Doctrine\DBAL\Connection`
available as services and map the `Opus\AuditBundle\Model` directory.

> **Requirements:** PostgreSQL (gapless sequencing uses advisory locks and the
> integrity model is built around it) and the `sodium` extension (AEAD
> encryption). The bundle integrates with Doctrine ORM via a single `onFlush`
> listener — no schema coupling.

<a id="quick-start"></a>
## Quick start

Declare what to audit on the entity:

```php
use Opus\AuditBundle\Attribute\{Auditable, AuditIgnore, Sensitive, DataSubject, Retention};

#[Auditable(stream: 'rechnung')]   // record this entity (opt-in)
#[Retention('10 years')]           // statutory keep duration
class Rechnung
{
    #[AuditIgnore]                  // never log this
    private string $internerToken;

    #[Sensitive]                    // encrypted + crypto-shred-able
    private string $kundenName;

    #[DataSubject]                  // whose key protects the sensitive data
    private Kunde $kunde;

    private string $status;
    private int $betrag;
}
```

Mutate inside a transaction so the audit row commits atomically with the change:

```php
$auditTransaction->run(function () use ($em, $rechnung): void {
    $rechnung->setStatus('open');
    $em->flush();
});
```

That's it — every create/update/delete is now recorded into a tamper-evident
chain, with `kundenName` encrypted per `Kunde`.

<a id="transactions"></a>
## The transaction requirement

Audit rows are written during Doctrine's `onFlush`, which fires *before*
Doctrine opens its own transaction. An enclosing transaction is therefore
**mandatory** — it is the only way the audit INSERT and the business change
share one commit boundary (atomicity) and the only way the per-stream advisory
lock stays held across the sequence read and insert (gaplessness).

Use the provided helper (or any transaction you already have):

```php
use Opus\AuditBundle\Transaction\AuditTransaction;

$auditTransaction->run(fn () => $em->flush());
```

Flushing an audited change **without** a transaction throws
`MissingAuditTransactionException` — loudly, never silently non-atomic.

This is the **atomicity trilemma** made explicit: of {gapless sequence, high
per-stream write concurrency, simple atomicity} you can have two. The Spine
chooses gaplessness + atomicity, serialising writes per stream. For hot
aggregates, choose a finer stream granularity (`#[Auditable(stream: ...)]` per
aggregate instance).

<a id="audit-trail"></a>
## How the audit trail works

- **Capture.** An `onFlush` listener reads the UnitOfWork change set, *including
  collection changes* (ManyToMany/OneToMany), and folds a collection mutation
  into one consolidated entry for its owner.
- **Identity.** Audited entities use application-side UUIDv7 ids, so the id is
  known before flush and the whole write happens in one pass.
- **Integrity.** Each entry stores
  `hash = sha256( canonical_json(entry) || previous_hash )`. Altering or dropping
  any historical entry breaks every later hash. A dedicated, deterministic
  canonical-JSON encoder (sorted keys, explicit float fractions, UTC-microsecond
  dates) guarantees a row re-reads and re-hashes byte-identically.
- **Sequencing.** `sequence_no` is gapless per stream, assigned under a
  PostgreSQL transaction-level advisory lock.
- **Actor.** Resolved from the security token (with impersonation detection), or
  set explicitly for CLI/Messenger via `AuditContext::runAs()`. Never a silent
  null — absence resolves to a `system` actor.
- **Sealing.** `audit:seal` checkpoints each stream's head `(last_sequence,
  head_hash)`; mirror seals to WORM/external storage. Verification then only
  re-walks the segment since the last trusted seal — O(segment), not O(stream).

Non-mutating, high-volume events (download, export, view, failed access) go to a
separate, lighter `audit_event` table via `AuditEventRecorder` — append-only but
deliberately *not* hash-chained, so reads never contend on the head lock.

<a id="crypto-shredding"></a>
## Crypto-shredding & retention

GoBD/BAO demands immutability over the retention period; GDPR Art. 17 demands
erasure on request. **Crypto-shredding** reconciles them:

- `#[Sensitive]` values are encrypted with a key derived per **data subject**
  (a DEK wrapped by your KEK). Erasure destroys the DEK; the ciphertext stays in
  place, so the hash-chain remains valid while the personal content becomes
  permanently unreadable. Non-personal metadata survives for archiving.
- **Multi-subject** values are encrypted under a key combined from *all* their
  subjects' DEKs, so destroying *any one* subject's key erases the value.
- **Actor PII** (label, IP, user-agent) is encrypted under the actor's own
  subject key — the acting person is a data subject too.
- A **legal hold** blocks shredding (Art. 17(3)(b)).

```php
// Erase everyone's data about a subject (the key is destroyed forever):
$keyStore->shred($subjectId);

// Block erasure while a matter is open:
$keyStore->placeLegalHold($subjectId);
```

**Retention** (`#[Retention('10 years')]`) bounds *purge* of whole entries.
Purge drops the leading past-retention block of a stream, writes a genesis seal
so the surviving chain stays verifiable, and never touches held entries or
classes with no policy. The default is conservative: **no automatic purge**.

<a id="attributes"></a>
## Attribute reference

| Attribute | Layer | Effect | Default without it |
|-----------|-------|--------|--------------------|
| `#[Auditable(stream?)]` | Spine | Entity is audited | not audited |
| `#[AuditIgnore]` | Spine | Field never logged | logged (if entity audited) |
| `#[Sensitive]` | Spine | Field encrypted + shred-able | plaintext |
| `#[DataSubject]` | Spine | Marks the subject for shredding | resolver heuristic |
| `#[Retention('…')]` | Spine | Keep duration | conservative (no auto-purge) |
| `#[AuditableAction(name)]` | Spine | Method logs an `audit_event` | — |

A field-name **deny heuristic** (`password`, `token`, `secret`, `*_key`, …)
masks suspicious values even without `#[AuditIgnore]` — a safety net, not a
substitute for the annotation.

<a id="configuration"></a>
## Configuration

```yaml
opus_audit:
    kek: '%env(OPUS_AUDIT_KEK)%'   # base64 of 32 bytes — required for crypto-shredding
    chain_backend: postgres        # default (E2 swaps this)
    keystore: doctrine             # default (KMS/Vault adapters later)
    retention:
        default: null              # null = keep forever; per class via #[Retention]
```

All values have safe defaults; an empty configuration is runnable (a KEK is
required only once you record sensitive data).

<a id="commands"></a>
## Console commands

| Command | Purpose |
|---------|---------|
| `audit:verify [--stream=…] [--full]` | Verify chains; non-zero exit on tampering (CI/monitoring). Incremental by default (since last seal). |
| `audit:seal [--stream=…]` | Write head checkpoints to mirror externally. |
| `audit:export […] [--format=json\|csv] [-o file]` | Export entries (JSON = full/decrypted, also the GDPR access deliverable; CSV = flat metadata). |
| `audit:purge [--stream=…] --force` | Remove entries past retention (honours legal holds). |

<a id="reading"></a>
## Reading & exporting

The stored entry stays encrypted (it is the source of truth for verification).
`AuditEntryReader` is the read-side projection that decrypts for display/export
and renders shredded values as `[redacted: erased]`, never throwing:

```php
$view = $auditEntryReader->toArray($entry); // decrypted, display-ready array
```

<a id="contracts"></a>
## Extension points (stable contracts)

These interfaces are BC-critical (strict SemVer). Swap the default
implementation by aliasing the interface to your service.

| Interface | Responsibility | Default |
|-----------|----------------|---------|
| `ActorResolverInterface` | Resolve the current actor | security token |
| `SubjectResolverInterface` | Resolve data subjects (incl. actors) | attribute + heuristic |
| `KeyStoreInterface` | Manage/derive/destroy DEKs | Doctrine DB |
| `RetentionPolicyInterface` | Retention per class | attribute-based |
| `ChainBackendInterface` | Append + verify the chain | Postgres hash-chain |

<a id="hardening"></a>
## Deployment hardening

The hash-chain makes tampering *evident*, not impossible. For real
immutability:

- Grant the application DB user only `INSERT` + `SELECT` on `audit_*` — no
  `UPDATE`/`DELETE` at the GRANT level.
- Mirror seals to WORM/external storage (`audit:seal` on a schedule).
- Keep the KEK in a secret store / Vault / KMS, never in the repository.

True **authorship** (proving *which* user wrote a record, even against the
operator) requires a signature with a server-foreign key — escalation tier E3.

<a id="limitations"></a>
## Honest limitations

- **Bulk operations** (`UPDATE`/`DELETE` via DQL or native SQL) bypass the
  UnitOfWork and are **not** captured. The listener gives *detective*, not
  provable, completeness. Where completeness must be provable, use tier E4
  (DB triggers).
- The chain proves "the log *says* U did X, and the log is unchanged" — not that
  U actually did it. Whoever controls the DB can fabricate an entry with a
  foreign `actor_id`. See hardening, and E3 for non-repudiation.
- Across a purge boundary, "nothing was omitted" is no longer provable; only the
  surviving chain is verifiable.

<a id="tiers"></a>
## Escalation tiers (roadmap)

| Tier | Delivers | When |
|------|----------|------|
| **E1** Versioning | diff, rollback, history | internal docs, applications |
| **E2** Strong integrity | Merkle proofs (immudb/Trillian) | explicit audit mandate |
| **E3** Authorship | qualified signatures / ID Austria (eIDAS) | decisions, approvals |
| **E4** High-assurance capture | DB triggers / CDC — captures bulk too | provable completeness |

The tiers are independent and opt-in. This package is **v1: the Spine**.

---

Released under the [MIT License](../LICENSE).
