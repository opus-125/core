<?php

declare(strict_types=1);

namespace Opus125\DataContracts\Attribute;

/**
 * Mark an entity class as a data subject — a natural person to whom personal
 * data belongs (GDPR Art. 4 No. 1).
 *
 * A data subject is the *root* of belonging: subject resolution stops here. Any
 * other entity that carries personal data reaches its subject through one or
 * more {@see SubjectLink} hops; the subject entity is itself the answer.
 *
 * Applying it does not imply every field is personal — declare those with
 * {@see PersonalData}. It only declares "records about this person hang off
 * here", which is what access and erasure traverse to.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class DataSubject
{
}
