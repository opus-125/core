<?php

declare(strict_types=1);

namespace Opus125\DataContracts\Subject;

/**
 * A stable, comparable handle to one data subject: its entity class and string
 * identifier.
 *
 * Used as the currency of every subject operation — the unit access collects
 * for, erasure acts on, and the crypto key store is keyed by — so the two
 * bundles can name "the same person" identically without sharing entities.
 */
final readonly class SubjectReference implements \Stringable
{
    /**
     * @param class-string $entityClass the data-subject entity class
     * @param string       $entityId    its identifier, rendered as a stable string
     */
    public function __construct(
        public string $entityClass,
        public string $entityId,
    ) {
        if ('' === $entityId) {
            throw new \InvalidArgumentException('SubjectReference entity id must not be empty.');
        }
    }

    public function equals(self $other): bool
    {
        return $this->entityClass === $other->entityClass && $this->entityId === $other->entityId;
    }

    /**
     * A canonical `Class#id` token, suitable as an array key for de-duplication
     * and as the associated-data / key-derivation label for crypto.
     */
    public function __toString(): string
    {
        return $this->entityClass.'#'.$this->entityId;
    }
}
