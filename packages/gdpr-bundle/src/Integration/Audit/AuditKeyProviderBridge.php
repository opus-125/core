<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Integration\Audit;

use Doctrine\ORM\EntityManagerInterface;
use Opus125\AuditBundle\Crypto\SubjectKeyProviderInterface;
use Opus125\DataContracts\Subject\SubjectReference;
use Opus125\GdprBundle\Crypto\KeyStoreInterface;
use Opus125\GdprBundle\Subject\SubjectResolver;

/**
 * Bridges the Audit bundle's per-entity encryption key onto the GDPR key store,
 * so crypto-shredding a subject also makes their `#[Sensitive]` audit values
 * unreadable.
 *
 * This is the **only** point of contact between the two bundles, and it is
 * additive and optional: the class is registered as the Audit bundle's
 * {@see SubjectKeyProviderInterface} only when that bundle is installed (a
 * `class_exists` guard in the service config), so neither bundle requires the
 * other. With it wired:
 *
 *  - on write, the audited entity is resolved to its data subject and the
 *    subject's DEK (minted on first use) encrypts the sensitive value;
 *  - after {@see \Opus125\GdprBundle\Erasure\ErasureService} crypto-shreds that
 *    subject, the key store returns null, so the audit value reads back redacted
 *    while the hash-chain stays valid.
 *
 * When the audited entity resolves to exactly one subject, that subject's key is
 * used; otherwise (no subject, or genuinely shared between several) the audited
 * entity acts as its own crypto subject, so a key is always available for writes.
 */
final class AuditKeyProviderBridge implements SubjectKeyProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SubjectResolver $resolver,
        private readonly KeyStoreInterface $keyStore,
    ) {
    }

    public function keyFor(string $entityClass, string $entityId): ?string
    {
        return $this->keyStore->ensureKey($this->subjectFor($entityClass, $entityId));
    }

    /**
     * @param class-string $entityClass
     */
    private function subjectFor(string $entityClass, string $entityId): SubjectReference
    {
        $self = new SubjectReference($entityClass, $entityId);

        if (!class_exists($entityClass) || $this->entityManager->getMetadataFactory()->isTransient($entityClass)) {
            return $self;
        }

        $entity = $this->entityManager->find($entityClass, $entityId);
        if (null === $entity) {
            return $self;
        }

        $subjects = $this->resolver->resolve($entity);

        return 1 === \count($subjects) ? $subjects[0] : $self;
    }
}
