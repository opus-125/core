<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Attribute;

/**
 * Never record a property in the audit trail (Spine).
 *
 * For values that must not appear in the log in any form — internal tokens,
 * secrets, cache fields, derived columns. The property is excluded from the
 * change set entirely: neither its old nor its new value is stored, not even
 * encrypted.
 *
 * Contrast with {@see Sensitive}, which still records the value but encrypts it
 * so it can be crypto-shredded. Use `#[AuditIgnore]` when the value should leave
 * no trace at all; use `#[Sensitive]` when it is auditable personal data.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class AuditIgnore
{
}
