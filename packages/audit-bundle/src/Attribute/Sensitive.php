<?php

declare(strict_types=1);

namespace Opus125\AuditBundle\Attribute;

/**
 * Mark a property as personal/sensitive data (Spine).
 *
 * The value is still recorded in the change set, but **encrypted** with a key
 * derived per data subject (see {@see DataSubject} and the crypto-shredding
 * model). Destroying that key — the GDPR Art. 17 erasure path — renders the
 * stored ciphertext permanently unreadable while leaving the hash-chain intact
 * (the ciphertext bytes stay, so every hash still verifies).
 *
 * A field-name deny heuristic (`password`, `token`, `secret`, `*_key`, …) acts
 * as a safety net and masks suspicious fields even without this attribute, but
 * the heuristic is a backstop, not a substitute for declaring intent here.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class Sensitive
{
}
