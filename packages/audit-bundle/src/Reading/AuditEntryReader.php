<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Reading;

use Opus\AuditBundle\Crypto\CryptoShredder;
use Opus\AuditBundle\Model\AuditEntry;

/**
 * Presents a stored {@see AuditEntry} with its `#[Sensitive]` content decrypted
 * (or rendered as the redaction placeholder where a subject has been shredded).
 *
 * The stored entry is the source of truth for verification and stays encrypted;
 * this reader is the read-side projection for display, export and access
 * requests. It never throws on shredded data — erased values come back as
 * {@see CryptoShredder::REDACTED}.
 */
final class AuditEntryReader
{
    public function __construct(
        private readonly CryptoShredder $shredder,
    ) {
    }

    /**
     * A fully decrypted, display-ready view of an entry.
     *
     * @return array<string, mixed>
     */
    public function toArray(AuditEntry $entry): array
    {
        return [
            'id' => $entry->getId(),
            'stream_id' => $entry->getStreamId(),
            'sequence_no' => $entry->getSequenceNo(),
            'occurred_at' => $entry->getOccurredAtString(),
            'action' => $entry->getAction()->value,
            'entity_class' => $entry->getEntityClass(),
            'entity_id' => $entry->getEntityId(),
            'actor_type' => $entry->getActorType()->value,
            'actor_id' => $entry->getActorId(),
            'actor_label' => $this->decryptLabel($entry->getActorLabel()),
            'changes' => $this->decryptMap($entry->getChanges()),
            'context' => $this->decryptMap($entry->getContext()),
            'legal_hold' => $entry->isLegalHold(),
            'hash' => $entry->getHash(),
        ];
    }

    /**
     * Decrypt every envelope found in a changes/context map, leaving plain
     * values untouched.
     *
     * @param array<string, mixed> $map
     *
     * @return array<string, mixed>
     */
    public function decryptMap(array $map): array
    {
        return array_map($this->decryptValue(...), $map);
    }

    public function decryptLabel(?string $label): ?string
    {
        if (null === $label) {
            return null;
        }

        $decoded = json_decode($label, true);
        if (\is_array($decoded) && CryptoShredder::isEnvelope($decoded)) {
            $value = $this->shredder->decryptValue($decoded);

            return \is_string($value) ? $value : (string) json_encode($value, \JSON_THROW_ON_ERROR);
        }

        return $label;
    }

    private function decryptValue(mixed $value): mixed
    {
        if (CryptoShredder::isEnvelope($value)) {
            /* @var array<string, mixed> $value */
            return $this->shredder->decryptValue($value);
        }

        if (\is_array($value)) {
            return array_map($this->decryptValue(...), $value);
        }

        return $value;
    }
}
