<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Erasure;

use Doctrine\ORM\EntityManagerInterface;
use Opus125\DataContracts\Erasure\ErasureStrategy;
use Opus125\DataContracts\Subject\SubjectReference;
use Opus125\GdprBundle\Crypto\KeyStoreInterface;
use Opus125\GdprBundle\Event\SubjectErased;
use Opus125\GdprBundle\Exception\SubjectNotFoundException;
use Opus125\GdprBundle\Registry\PersonalDataRegistry;
use Opus125\GdprBundle\Subject\SubjectRecordCollector;
use Opus125\GdprBundle\Support\EntityIdentifier;
use Opus125\GdprBundle\Support\SubjectLoader;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Erasure & anonymisation (GDPR Art. 17).
 *
 * "Erasure" is applied per field according to the strategy declared on
 * {@see \Opus125\DataContracts\Attribute\PersonalData}:
 *
 *  - `nullify`      — clear the live value;
 *  - `pseudonymize` — replace it with a stable pseudonym (references and
 *                     statistics survive, the person does not);
 *  - `crypto_shred` — destroy the subject's data key, rendering their ciphertext
 *                     in append-only stores (audit/revision) permanently
 *                     unreadable while the bytes and any hash-chain stay intact.
 *
 * A field with **no** declared strategy is left untouched (conservative default).
 * Legal hold (Art. 17 (3)) refuses the whole request with a recorded reason.
 * Genuinely shared data — a linked record reachable from another subject — is
 * skipped rather than stripped from someone who did not ask. The resulting
 * {@see ErasureReport} is dispatched as {@see SubjectErased} so the act can be
 * audited without coupling to the Audit bundle.
 */
final class ErasureService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PersonalDataRegistry $registry,
        private readonly SubjectRecordCollector $collector,
        private readonly SubjectLoader $subjectLoader,
        private readonly LegalHoldInterface $legalHold,
        private readonly Pseudonymizer $pseudonymizer,
        private readonly ReferenceChecker $referenceChecker,
        private readonly KeyStoreInterface $keyStore,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
    ) {
    }

    /**
     * @param bool $flush whether to flush the erased entities immediately
     *
     * @throws SubjectNotFoundException when the subject entity cannot be loaded
     */
    public function erase(SubjectReference $subject, bool $flush = true): ErasureReport
    {
        if ($this->legalHold->isHeld($subject)) {
            return ErasureReport::blocked($subject, $this->legalHold->reason($subject));
        }

        $subjectEntity = $this->subjectLoader->load($subject);
        if (null === $subjectEntity) {
            throw SubjectNotFoundException::for($subject);
        }

        $fields = [];
        $shredKey = false;

        foreach ($this->collector->collect($subjectEntity, $subject) as $entity) {
            $class = $this->entityManager->getClassMetadata($entity::class)->getName();
            $id = EntityIdentifier::of($this->entityManager, $entity);
            $isOwn = $class === $subject->entityClass && $id === $subject->entityId;

            foreach ($this->registry->personalData($class) as $field) {
                $strategy = $field->erasure;
                if (null === $strategy) {
                    continue; // no policy → leave it (conservative default).
                }

                if (ErasureStrategy::CryptoShred === $strategy) {
                    $shredKey = true;
                    $fields[] = new FieldErasure($class, $id, $field->property, ErasureOutcome::CryptoShredded);

                    continue;
                }

                // Live mutation (nullify / pseudonymize): protect shared data.
                if (!$isOwn && $this->referenceChecker->isSharedBeyond($entity, $subject)) {
                    $fields[] = new FieldErasure($class, $id, $field->property, ErasureOutcome::SkippedShared);

                    continue;
                }

                $outcome = $this->applyLiveStrategy($entity, $class, $field->property, $strategy, $subject);
                $fields[] = new FieldErasure($class, $id, $field->property, $outcome);
            }
        }

        if ($shredKey) {
            $this->keyStore->shred($subject);
        }

        if ($flush) {
            $this->entityManager->flush();
        }

        $report = ErasureReport::completed($subject, $shredKey, $fields);
        $this->eventDispatcher?->dispatch(new SubjectErased($report));

        return $report;
    }

    /**
     * @param class-string $class
     */
    private function applyLiveStrategy(object $entity, string $class, string $property, ErasureStrategy $strategy, SubjectReference $subject): ErasureOutcome
    {
        $reflection = new \ReflectionProperty($class, $property);

        if (ErasureStrategy::Pseudonymize === $strategy && $this->acceptsString($reflection)) {
            $reflection->setValue($entity, $this->pseudonymizer->pseudonym($subject, $property));

            return ErasureOutcome::Pseudonymized;
        }

        // Nullify, or pseudonymize fell back because the field is not a string.
        $reflection->setValue($entity, $this->emptyValueFor($reflection));

        return ErasureOutcome::Nullified;
    }

    private function acceptsString(\ReflectionProperty $property): bool
    {
        $type = $property->getType();

        if (!$type instanceof \ReflectionNamedType) {
            return true; // untyped or union — accept the string pseudonym.
        }

        return \in_array($type->getName(), ['string', 'mixed'], true);
    }

    private function emptyValueFor(\ReflectionProperty $property): mixed
    {
        $type = $property->getType();

        if (!$type instanceof \ReflectionNamedType || $type->allowsNull()) {
            return null;
        }

        return match ($type->getName()) {
            'string' => '',
            'int' => 0,
            'float' => 0.0,
            'bool' => false,
            'array' => [],
            default => null,
        };
    }
}
