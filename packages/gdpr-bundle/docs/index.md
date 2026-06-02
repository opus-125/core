# Opus125 GdprBundle

Registry-driven GDPR tooling for Symfony with Doctrine. Declare your personal
data once with three attributes; subject access, portability, erasure and the
records of processing are read off that declaration.

- **Symfony 8 · PHP 8.4+ · Doctrine ORM 3** — works on any Doctrine DBAL platform.
- Zero configuration to start; customised through **interfaces** and **events**,
  no bundle config keys.
- Without an attribute, nothing is considered personal data.

## How it works

- The **Personal-Data-Registry** reads `#[DataSubject]`, `#[PersonalData]` and
  `#[SubjectLink]` (from `opus125/data-contracts`) off your entities.
- **Subject resolution** walks the declared object graph from any record to its
  subject(s): direct, multi-level (`Position → Order → Contact`), shared (several
  subjects), with cycle detection and a conservative "if in doubt, do not erase".
- **Access** (`SubjectAccessService`) inverts that to gather every personal field
  belonging to a subject, across every linked entity, into a report exported as
  JSON or CSV.
- **Erasure** (`ErasureService`) applies the per-field strategy — `nullify`,
  `pseudonymize`, or `crypto_shred` (destroy the subject's data key) — honouring
  legal hold and shared-data reference checks.
- **Records of processing** (`RecordsOfProcessingGenerator`) builds an Art. 30
  scaffold from the `category`/`purpose`/`basis` metadata.

## Attributes (from `opus125/data-contracts`)

| Attribute | Effect |
|-----------|--------|
| `#[DataSubject]` | The entity is a natural person (root of belonging) |
| `#[PersonalData(category, purpose?, basis?, sensitive?, erasure?)]` | A personal-data field |
| `#[SubjectLink(target)]` | Links personal data on another entity to its subject |

`ErasureStrategy`: `nullify` · `pseudonymize` · `crypto_shred`.

## Commands

| Command | Purpose |
|---------|---------|
| `gdpr:export <class> <id> [--format=json\|csv] [-o file]` | Art. 15 / 20 export |
| `gdpr:erase <class> <id> --force` | Art. 17 erasure |
| `gdpr:ropa:export [--format=json\|table]` | Art. 30 records of processing |

## Seams

| Interface | Responsibility | Default |
|-----------|----------------|---------|
| `SubjectResolverInterface` | Record → subject(s) | graph traversal |
| `KeyStoreInterface` | Per-subject DEK (crypto-shredding) | `gdpr_subject_key` table |
| `LegalHoldInterface` | Retention overriding erasure | none held |

## Events

`SubjectErased` is dispatched after each (non-blocked) erasure, carrying the
report — listen to it to record the act in your audit trail.

## Optional Audit integration

With `opus125/audit-bundle` installed, alias its key provider to
`AuditKeyProviderBridge` so crypto-shredding a subject also makes their
`#[Sensitive]` audit values unreadable while the hash-chain stays valid. The two
bundles share only the `opus125/data-contracts` vocabulary — no code dependency
either way.

## Good to know

- **Encrypted live fields** lose `ORDER BY`/`LIKE` capability; the bundle keeps
  operational fields (e.g. a CRM name) in cleartext and *transforms* them on
  erasure, reserving crypto-shredding for append-only history.
- Subject resolution traverses Doctrine associations; bulk DQL that bypasses the
  object graph is not considered.
- The bundle stores the declared legal `basis`; it never assesses it.

Released under the [MIT License](../LICENSE).
