<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Model;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Opus\AuditBundle\Repository\CryptoKeyRepository;

/**
 * The per-data-subject key record backing crypto-shredding (Spine).
 *
 * Each data subject owns a Data Encryption Key (DEK). The DEK is never stored in
 * the clear: it is kept {@see $wrappedDek wrapped} by a Key Encryption Key
 * (KEK). Sensitive audit values are encrypted with a key derived from the
 * subject's DEK(s).
 *
 * GDPR Art. 17 erasure ("crypto-shredding") destroys the DEK: {@see $wrappedDek}
 * is nulled and {@see $shreddedAt} stamped. The ciphertext in the audit entries
 * stays physically in place — so the hash-chain remains valid — but is now
 * permanently undecryptable, erasing the personal content while preserving the
 * non-personal record for statutory retention.
 *
 * A {@see $legalHold} blocks shredding (Art. 17(3)(b)).
 */
#[ORM\Entity(repositoryClass: CryptoKeyRepository::class)]
#[ORM\Table(name: Schema::KEY_TABLE)]
class CryptoKey
{
    #[ORM\Id]
    #[ORM\Column(name: 'subject_id', type: Types::STRING, length: 255)]
    private string $subjectId;

    /**
     * Base64 of `nonce || AEAD(DEK)` under the KEK, or null once shredded.
     */
    #[ORM\Column(name: 'wrapped_dek', type: Types::TEXT, nullable: true)]
    private ?string $wrappedDek;

    /**
     * Identifier of the KEK used to wrap the DEK, so key rotation can tell which
     * master key a record needs.
     */
    #[ORM\Column(name: 'kek_id', type: Types::STRING, length: 64)]
    private string $kekId;

    #[ORM\Column(name: 'created_at', type: Types::STRING, length: 32)]
    private string $createdAt;

    #[ORM\Column(name: 'shredded_at', type: Types::STRING, length: 32, nullable: true)]
    private ?string $shreddedAt;

    #[ORM\Column(name: 'legal_hold', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $legalHold;

    public function __construct(
        string $subjectId,
        ?string $wrappedDek,
        string $kekId,
        string $createdAt,
        ?string $shreddedAt = null,
        bool $legalHold = false,
    ) {
        $this->subjectId = $subjectId;
        $this->wrappedDek = $wrappedDek;
        $this->kekId = $kekId;
        $this->createdAt = $createdAt;
        $this->shreddedAt = $shreddedAt;
        $this->legalHold = $legalHold;
    }

    public function getSubjectId(): string
    {
        return $this->subjectId;
    }

    public function getWrappedDek(): ?string
    {
        return $this->wrappedDek;
    }

    public function getKekId(): string
    {
        return $this->kekId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->createdAt);
    }

    public function isShredded(): bool
    {
        return null === $this->wrappedDek;
    }

    public function getShreddedAt(): ?\DateTimeImmutable
    {
        return null === $this->shreddedAt ? null : new \DateTimeImmutable($this->shreddedAt);
    }

    public function isLegalHold(): bool
    {
        return $this->legalHold;
    }

    public function shred(string $shreddedAt): void
    {
        $this->wrappedDek = null;
        $this->shreddedAt = $shreddedAt;
    }

    public function placeLegalHold(): void
    {
        $this->legalHold = true;
    }

    public function liftLegalHold(): void
    {
        $this->legalHold = false;
    }
}
