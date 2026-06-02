<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Crypto;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Opus125\DataContracts\Subject\SubjectReference;
use Opus125\GdprBundle\Exception\DecryptionFailedException;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Database-backed {@see KeyStoreInterface}: one wrapped DEK row per subject in
 * `gdpr_subject_key`.
 *
 * Each subject gets a random DEK, stored **wrapped** under a key-encryption key
 * (KEK) derived from the application secret with HKDF — the DEK never touches the
 * database in the clear. Shredding nulls the wrapped key and writes a tombstone
 * so the subject can never be re-keyed.
 *
 * Reads and writes go through DBAL directly rather than the ORM unit of work, so
 * a key can be minted on demand from inside another flush (e.g. while the Audit
 * bundle is encrypting a sensitive value) without disturbing it, and remains
 * transaction-safe. The `SubjectKey` entity exists for schema generation and
 * auto-mapping.
 */
final class DoctrineKeyStore implements KeyStoreInterface
{
    private const string TABLE = 'gdpr_subject_key';
    private const int KEK_BYTES = 32;
    private const string KEK_LABEL = 'opus125-gdpr:kek:v1';

    private readonly string $kek;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Cipher $cipher,
        private readonly ClockInterface $clock,
        #[\SensitiveParameter]
        string $secret,
    ) {
        if ('' === $secret) {
            throw new \InvalidArgumentException('The application secret must not be empty; it derives the key-encryption key.');
        }

        $this->kek = hash_hkdf('sha256', $secret, self::KEK_BYTES, self::KEK_LABEL);
    }

    public function keyFor(SubjectReference $subject): ?string
    {
        $row = $this->row($subject);
        if (null === $row || true === $row['shredded'] || null === $row['wrapped_key']) {
            return null;
        }

        return $this->unwrap($subject, (string) $row['wrapped_key']);
    }

    public function ensureKey(SubjectReference $subject): ?string
    {
        $row = $this->row($subject);

        if (null !== $row) {
            if (true === $row['shredded']) {
                return null; // never resurrect a shredded subject.
            }

            return null === $row['wrapped_key'] ? null : $this->unwrap($subject, (string) $row['wrapped_key']);
        }

        $dek = $this->cipher->generateKey();
        $wrapped = base64_encode($this->cipher->encrypt($this->kek, $dek, (string) $subject));

        try {
            $this->connection()->insert(self::TABLE, [
                'id' => Uuid::v7()->toRfc4122(),
                'subject_class' => $subject->entityClass,
                'subject_id' => $subject->entityId,
                'wrapped_key' => $wrapped,
                'shredded' => 0,
                'created_at' => $this->now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent writer created (or shredded) it first — defer to theirs.
            return $this->keyFor($subject);
        }

        return $dek;
    }

    public function shred(SubjectReference $subject): void
    {
        $connection = $this->connection();
        $affected = $connection->update(
            self::TABLE,
            ['wrapped_key' => null, 'shredded' => 1, 'shredded_at' => $this->now()],
            ['subject_class' => $subject->entityClass, 'subject_id' => $subject->entityId],
        );

        if (0 !== $affected) {
            return;
        }

        // No key was ever created: write a tombstone so one never can be.
        try {
            $connection->insert(self::TABLE, [
                'id' => Uuid::v7()->toRfc4122(),
                'subject_class' => $subject->entityClass,
                'subject_id' => $subject->entityId,
                'wrapped_key' => null,
                'shredded' => 1,
                'created_at' => $this->now(),
                'shredded_at' => $this->now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Created concurrently between the UPDATE and the INSERT — re-shred.
            $connection->update(
                self::TABLE,
                ['wrapped_key' => null, 'shredded' => 1, 'shredded_at' => $this->now()],
                ['subject_class' => $subject->entityClass, 'subject_id' => $subject->entityId],
            );
        }
    }

    public function isShredded(SubjectReference $subject): bool
    {
        $row = $this->row($subject);

        return null !== $row && true === $row['shredded'];
    }

    /**
     * @return array{wrapped_key: string|null, shredded: bool}|null
     */
    private function row(SubjectReference $subject): ?array
    {
        /** @var array{wrapped_key: string|null, shredded: bool|int|string}|false $row */
        $row = $this->connection()->createQueryBuilder()
            ->select('wrapped_key', 'shredded')
            ->from(self::TABLE)
            ->where('subject_class = :class')
            ->andWhere('subject_id = :id')
            ->setParameter('class', $subject->entityClass)
            ->setParameter('id', $subject->entityId)
            ->fetchAssociative();

        if (false === $row) {
            return null;
        }

        return [
            'wrapped_key' => $row['wrapped_key'],
            'shredded' => (bool) $row['shredded'],
        ];
    }

    private function unwrap(SubjectReference $subject, string $wrapped): ?string
    {
        $blob = base64_decode($wrapped, true);
        if (false === $blob) {
            return null;
        }

        try {
            return $this->cipher->decrypt($this->kek, $blob, (string) $subject);
        } catch (DecryptionFailedException) {
            return null;
        }
    }

    private function connection(): \Doctrine\DBAL\Connection
    {
        return $this->entityManager->getConnection();
    }

    private function now(): string
    {
        return $this->clock->now()->format(\DateTimeInterface::RFC3339);
    }
}
