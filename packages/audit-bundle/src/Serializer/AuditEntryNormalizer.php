<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Serializer;

use Opus\AuditBundle\Crypto\CryptoShredder;
use Opus\AuditBundle\Model\AuditEntryInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Serializer normalizer for {@see AuditEntryInterface}.
 *
 * Produces a plain, export-ready array and transparently decrypts `#[Sensitive]`
 * envelopes in the changes/context and the actor label — shredded values come
 * back as {@see CryptoShredder::REDACTED}, never as cleartext and never throwing.
 * Use the Symfony Serializer (`$serializer->normalize($entry)` /
 * `serialize($entries, 'json'|'csv')`) for any export need; the bundle ships no
 * export command of its own.
 */
final class AuditEntryNormalizer implements NormalizerInterface
{
    public function __construct(
        private readonly CryptoShredder $shredder,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        if (!$data instanceof AuditEntryInterface) {
            throw new \InvalidArgumentException('Expected an AuditEntryInterface.');
        }

        return [
            'id' => $data->getId(),
            'stream' => $data->getStream(),
            'sequence_no' => $data->getSequenceNo(),
            'occurred_at' => $data->getOccurredAt()->format(\DATE_RFC3339),
            'action' => $data->getAction(),
            'entity_class' => $data->getEntityClass(),
            'entity_id' => $data->getEntityId(),
            'actor_type' => $data->getActorType()->value,
            'actor_id' => $data->getActorId(),
            'actor_label' => $this->decryptLabel($data->getActorLabel()),
            'changes' => $this->decryptMap($data->getChanges()),
            'context' => $this->decryptMap($data->getContext()),
            'hash' => $data->getHash(),
        ];
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof AuditEntryInterface;
    }

    /**
     * @return array<class-string|'*'|'object'|string, bool|null>
     */
    public function getSupportedTypes(?string $format): array
    {
        return [AuditEntryInterface::class => true];
    }

    /**
     * @param array<string, mixed> $map
     *
     * @return array<string, mixed>
     */
    public function decryptMap(array $map): array
    {
        return array_map($this->decrypt(...), $map);
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

    private function decrypt(mixed $value): mixed
    {
        if (\is_array($value) && CryptoShredder::isEnvelope($value)) {
            return $this->shredder->decryptValue($value);
        }

        if (\is_array($value)) {
            return array_map($this->decrypt(...), $value);
        }

        return $value;
    }
}
