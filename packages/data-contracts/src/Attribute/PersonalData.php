<?php

declare(strict_types=1);

namespace Opus125\DataContracts\Attribute;

use Opus125\DataContracts\Erasure\ErasureStrategy;

/**
 * Mark a property as personal data and describe how it is processed.
 *
 * This is the atom of the Personal-Data-Registry: a declarative map of which
 * fields carry personal data, for which purpose, on which legal basis, and how
 * they must be erased. Every subject right (access, portability, erasure) and
 * the records-of-processing export are read *off* these annotations rather than
 * re-implemented per feature.
 *
 * The attribute only *stores* the declared `basis`; it never evaluates whether a
 * legal basis is valid — that assessment is the controller's process, not code.
 *
 * Lives in `opus125/data-contracts` so both the GDPR bundle (which acts on it)
 * and the Audit bundle (which may read it to mask sensitive fields) share one
 * vocabulary without depending on each other. The signature is BC-critical.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class PersonalData
{
    public function __construct(
        /**
         * Data category, e.g. `name`, `contact`, `address`, `health`. Free-form
         * but conventionally a short slug; it groups fields in exports and the
         * records of processing.
         */
        public string $category,
        /**
         * Processing purpose, e.g. `crm`, `shipping`, `support`.
         */
        public ?string $purpose = null,
        /**
         * Declared legal basis, e.g. `contract`, `consent`, `legal_obligation`.
         * Stored verbatim; never assessed.
         */
        public ?string $basis = null,
        /**
         * Whether the field is an Art. 9 special category (health, biometrics,
         * …). A sensitive field warrants stricter handling — e.g. the Audit
         * bundle can mask/encrypt it instead of logging cleartext.
         */
        public bool $sensitive = false,
        /**
         * How the field is erased on an Art. 17 request. `null` means the field
         * has no declared erasure policy and is left untouched — the
         * conservative default ("never erase without an explicit policy").
         *
         * Accepts the {@see ErasureStrategy} enum or its string value so both
         * `erasure: ErasureStrategy::Pseudonymize` and `erasure: 'pseudonymize'`
         * are valid.
         */
        public ErasureStrategy|string|null $erasure = null,
    ) {
        if ('' === $category) {
            throw new \InvalidArgumentException('PersonalData category must not be empty.');
        }

        if (\is_string($erasure) && null === ErasureStrategy::tryFrom($erasure)) {
            throw new \InvalidArgumentException(\sprintf('Unknown erasure strategy "%s"; expected one of: %s.', $erasure, implode(', ', array_map(static fn (ErasureStrategy $s): string => $s->value, ErasureStrategy::cases()))));
        }
    }

    /**
     * The declared erasure strategy as the typed enum, or null when none was
     * declared.
     */
    public function erasureStrategy(): ?ErasureStrategy
    {
        if (null === $this->erasure) {
            return null;
        }

        return $this->erasure instanceof ErasureStrategy ? $this->erasure : ErasureStrategy::from($this->erasure);
    }
}
