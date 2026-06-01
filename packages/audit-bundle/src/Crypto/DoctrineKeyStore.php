<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Crypto;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Opus\AuditBundle\Crypto\Exception\LegalHoldViolationException;
use Opus\AuditBundle\Model\Schema;
use Opus\AuditBundle\Support\CanonicalTimestamp;
use Psr\Clock\ClockInterface;

/**
 * Default {@see KeyStoreInterface}: per-subject DEKs stored in the database,
 * each wrapped by a configured Key Encryption Key (KEK).
 *
 * All access goes through DBAL rather than the ORM UnitOfWork, because DEKs are
 * minted on the audit write path *inside* `onFlush` and must participate in the
 * same wrapping transaction as the audit rows (see
 * {@see \Opus\AuditBundle\Recording\DoctrineAuditListener}).
 *
 * The combined value key for a set of subjects is
 * `BLAKE2b( DEK_{s1} || DEK_{s2} || … )` over the subjects in sorted order, so
 * it is order-independent and — crucially — *unrecoverable* once any single
 * contributing DEK is destroyed. That is what makes shredding one subject erase
 * shared multi-subject content.
 *
 * Re-provisioning: encrypting new data for a previously shredded subject mints a
 * *fresh* DEK (lawful new processing). Old ciphertext stays bound to the
 * destroyed key and remains unreadable — the AEAD tag guarantees the new key
 * cannot decrypt it.
 */
final class DoctrineKeyStore implements KeyStoreInterface
{
    private const int DEK_BYTES = Cipher::KEY_BYTES;

    /**
     * Process-lifetime cache of unwrapped DEKs, keyed by subject id. Avoids
     * re-unwrapping within a flush; invalidated on shred.
     *
     * @var array<string, string>
     */
    private array $cache = [];

    /**
     * @param string $kek   the 32-byte master key wrapping all DEKs
     * @param string $kekId a stable identifier for the KEK (for rotation)
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly Cipher $cipher,
        private readonly ClockInterface $clock,
        #[\SensitiveParameter]
        private readonly string $kek,
        private readonly string $kekId,
    ) {
        if (self::DEK_BYTES !== \strlen($kek)) {
            throw new \InvalidArgumentException(\sprintf('KEK must be exactly %d bytes.', self::DEK_BYTES));
        }
    }

    public function deriveEncryptionKey(array $subjectIds): string
    {
        $subjectIds = $this->normaliseSubjects($subjectIds);

        $deks = array_map($this->getOrCreateDek(...), $subjectIds);

        return $this->combine($deks);
    }

    public function deriveDecryptionKey(array $subjectIds): ?string
    {
        $subjectIds = $this->normaliseSubjects($subjectIds);

        $deks = [];
        foreach ($subjectIds as $subjectId) {
            $dek = $this->getLiveDek($subjectId);
            if (null === $dek) {
                return null;
            }
            $deks[] = $dek;
        }

        return $this->combine($deks);
    }

    public function shred(string $subjectId): void
    {
        if ($this->isUnderLegalHold($subjectId)) {
            throw LegalHoldViolationException::forSubject($subjectId);
        }

        unset($this->cache[$subjectId]);

        $this->connection->executeStatement(
            \sprintf('UPDATE %s SET wrapped_dek = NULL, shredded_at = :now WHERE subject_id = :id', Schema::KEY_TABLE),
            ['now' => $this->now(), 'id' => $subjectId],
        );
    }

    public function isShredded(string $subjectId): bool
    {
        $row = $this->fetch($subjectId);

        return null !== $row && null === $row['wrapped_dek'];
    }

    public function hasSubject(string $subjectId): bool
    {
        return null !== $this->fetch($subjectId);
    }

    public function placeLegalHold(string $subjectId): void
    {
        // Ensure the subject exists (with a live DEK) so a hold can be recorded.
        $this->getOrCreateDek($subjectId);

        $this->connection->executeStatement(
            \sprintf('UPDATE %s SET legal_hold = :hold WHERE subject_id = :id', Schema::KEY_TABLE),
            ['hold' => true, 'id' => $subjectId],
            ['hold' => ParameterType::BOOLEAN],
        );
    }

    public function liftLegalHold(string $subjectId): void
    {
        $this->connection->executeStatement(
            \sprintf('UPDATE %s SET legal_hold = :hold WHERE subject_id = :id', Schema::KEY_TABLE),
            ['hold' => false, 'id' => $subjectId],
            ['hold' => ParameterType::BOOLEAN],
        );
    }

    public function isUnderLegalHold(string $subjectId): bool
    {
        $row = $this->fetch($subjectId);

        return null !== $row && (bool) $row['legal_hold'];
    }

    private function getOrCreateDek(string $subjectId): string
    {
        if (isset($this->cache[$subjectId])) {
            return $this->cache[$subjectId];
        }

        $row = $this->fetch($subjectId);
        if (null !== $row && null !== $row['wrapped_dek']) {
            return $this->cache[$subjectId] = $this->unwrap((string) $row['wrapped_dek'], $subjectId);
        }

        $dek = $this->cipher->generateKey();
        $wrapped = base64_encode($this->cipher->encrypt($this->kek, $dek, $subjectId));

        // Upsert: insert a fresh DEK, or revive a *shredded* subject. An already
        // live DEK wins the conflict (the WHERE guard skips the update), and the
        // authoritative value is re-read below.
        $this->connection->executeStatement(
            \sprintf(
                'INSERT INTO %s (subject_id, wrapped_dek, kek_id, created_at, shredded_at, legal_hold)
                 VALUES (:id, :wrapped, :kek, :now, NULL, false)
                 ON CONFLICT (subject_id) DO UPDATE
                    SET wrapped_dek = EXCLUDED.wrapped_dek, kek_id = EXCLUDED.kek_id, shredded_at = NULL
                    WHERE %s.wrapped_dek IS NULL',
                Schema::KEY_TABLE,
                Schema::KEY_TABLE,
            ),
            ['id' => $subjectId, 'wrapped' => $wrapped, 'kek' => $this->kekId, 'now' => $this->now()],
        );

        $authoritative = $this->fetch($subjectId);
        if (null === $authoritative || null === $authoritative['wrapped_dek']) {
            // Should not happen: we just inserted/kept a live row.
            throw new \RuntimeException(\sprintf('Failed to provision a DEK for subject "%s".', $subjectId));
        }

        return $this->cache[$subjectId] = $this->unwrap((string) $authoritative['wrapped_dek'], $subjectId);
    }

    private function getLiveDek(string $subjectId): ?string
    {
        if (isset($this->cache[$subjectId])) {
            return $this->cache[$subjectId];
        }

        $row = $this->fetch($subjectId);
        if (null === $row || null === $row['wrapped_dek']) {
            return null;
        }

        return $this->cache[$subjectId] = $this->unwrap((string) $row['wrapped_dek'], $subjectId);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetch(string $subjectId): ?array
    {
        $row = $this->connection->fetchAssociative(
            \sprintf('SELECT wrapped_dek, legal_hold FROM %s WHERE subject_id = :id', Schema::KEY_TABLE),
            ['id' => $subjectId],
        );

        return false === $row ? null : $row;
    }

    private function unwrap(string $wrappedBase64, string $subjectId): string
    {
        $blob = base64_decode($wrappedBase64, true);
        if (false === $blob) {
            throw new \RuntimeException(\sprintf('Corrupt wrapped DEK for subject "%s".', $subjectId));
        }

        return $this->cipher->decrypt($this->kek, $blob, $subjectId);
    }

    /**
     * @param list<string> $deks
     */
    private function combine(array $deks): string
    {
        return sodium_crypto_generichash(implode('', $deks), '', self::DEK_BYTES);
    }

    /**
     * @param list<string> $subjectIds
     *
     * @return list<string>
     */
    private function normaliseSubjects(array $subjectIds): array
    {
        $subjectIds = array_values(array_unique(array_filter($subjectIds, static fn (string $s): bool => '' !== $s)));

        if ([] === $subjectIds) {
            throw new \InvalidArgumentException('At least one data subject is required to derive a key.');
        }

        sort($subjectIds, \SORT_STRING);

        return $subjectIds;
    }

    private function now(): string
    {
        return CanonicalTimestamp::format($this->clock->now());
    }
}
