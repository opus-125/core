<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Crypto;

use Doctrine\ORM\EntityManagerInterface;
use Opus\AuditBundle\Model\ShreddedSubject;
use Opus\AuditBundle\Repository\ShreddedSubjectRepository;
use Psr\Clock\ClockInterface;

/**
 * Default {@see SubjectKeyProviderInterface}: derives a per-subject key from the
 * application secret with HKDF, and records shredded subjects as tombstones.
 *
 * No per-subject key material is stored — keys are recomputed on demand from
 * `APP_SECRET` — which keeps the default zero-config. The trade-off, stated
 * honestly: because the key is derivable from the master secret, erasure here is
 * enforced by *refusing* to re-derive (the tombstone), not by destroying key
 * material. For erasure that holds even against someone with the master secret,
 * swap in a provider that stores and deletes random keys.
 */
final class DerivedSubjectKeyProvider implements SubjectKeyProviderInterface
{
    private const int KEY_BYTES = 32;
    private const string HKDF_ALGO = 'sha256';

    public function __construct(
        #[\SensitiveParameter]
        private readonly string $secret,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
        if ('' === $secret) {
            throw new \InvalidArgumentException('The application secret must not be empty; it seeds the crypto-shredding keys.');
        }
    }

    public function keyForSubject(string $subjectId): ?string
    {
        if ($this->isShredded($subjectId)) {
            return null;
        }

        return hash_hkdf(self::HKDF_ALGO, $this->secret, self::KEY_BYTES, 'opus-audit-subject:'.$subjectId);
    }

    public function shred(string $subjectId): void
    {
        if ($this->isShredded($subjectId)) {
            return;
        }

        $this->entityManager->persist(new ShreddedSubject($subjectId, \DateTimeImmutable::createFromInterface($this->clock->now())));
        $this->entityManager->flush();
    }

    public function isShredded(string $subjectId): bool
    {
        return $this->repository()->isShredded($subjectId);
    }

    private function repository(): ShreddedSubjectRepository
    {
        /** @var ShreddedSubjectRepository $repository */
        $repository = $this->entityManager->getRepository(ShreddedSubject::class);

        return $repository;
    }
}
