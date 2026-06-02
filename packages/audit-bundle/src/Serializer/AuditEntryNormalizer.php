<?php

declare(strict_types=1);

namespace Opus125\AuditBundle\Serializer;

use Opus125\AuditBundle\Crypto\SensitiveValueCipher;
use Opus125\AuditBundle\Crypto\SubjectKeyProviderInterface;
use Opus125\AuditBundle\Model\AuditEntryInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Serializer normalizer for {@see AuditEntryInterface}.
 *
 * Produces a plain, export-ready array and transparently decrypts `#[Sensitive]`
 * values using the key the {@see SubjectKeyProviderInterface} returns for the
 * entry's subject (its audited entity). Erased values come back as
 * {@see SensitiveValueCipher::REDACTED}, never as cleartext and never throwing.
 * Use the Symfony Serializer for any export need.
 */
final class AuditEntryNormalizer implements NormalizerInterface
{
    public function __construct(
        private readonly SubjectKeyProviderInterface $keyProvider,
        private readonly SensitiveValueCipher $cipher,
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

        $key = $this->keyFor($data);

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
            'actor_label' => $data->getActorLabel(),
            'changes' => $this->decryptMap($data->getChanges(), $key),
            'context' => $this->decryptMap($data->getContext(), $key),
            'hash' => $data->getHash(),
        ];
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof AuditEntryInterface;
    }

    /**
     * @return array<class-string, true>
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
    public function decryptMap(array $map, ?string $key): array
    {
        return array_map(fn (mixed $value): mixed => $this->decrypt($value, $key), $map);
    }

    private function keyFor(AuditEntryInterface $entry): ?string
    {
        $class = $entry->getEntityClass();
        $id = $entry->getEntityId();

        if (null === $class || null === $id || !class_exists($class)) {
            return null;
        }

        return $this->keyProvider->keyFor($class, $id);
    }

    private function decrypt(mixed $value, ?string $key): mixed
    {
        if (SensitiveValueCipher::isEnvelope($value)) {
            /* @var array<string, mixed> $value */
            return $this->cipher->decrypt($value, $key);
        }

        if (\is_array($value)) {
            return array_map(fn (mixed $v): mixed => $this->decrypt($v, $key), $value);
        }

        return $value;
    }
}
