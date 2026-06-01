<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Attribute;

/**
 * Identify the data subject(s) a record concerns, for crypto-shredding (Spine).
 *
 * Crypto-shredding answers "delete this person's data" by destroying the
 * encryption key derived for that person. To do that the bundle must know
 * *whose* key protects a given {@see Sensitive} value. This attribute marks the
 * property that points at the subject — typically the association to the person
 * the record is about (e.g. the `Kunde` on an invoice).
 *
 * The marked property may be:
 *  - an association whose target carries an identifier, or
 *  - a scalar that *is* the subject identifier.
 *
 * Multiple properties may be marked, yielding a multi-subject record: by default
 * the sensitive values are encrypted so that destroying *any one* subject's key
 * makes them unreadable. When no property is marked, the
 * {@see \Opus\AuditBundle\Subject\SubjectResolverInterface} falls back to a
 * heuristic — and it also covers the *actor* as a subject, since the acting
 * person's identity/label is personal data too.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class DataSubject
{
}
