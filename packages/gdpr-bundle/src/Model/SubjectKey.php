<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Model;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * The wrapped data-encryption key (DEK) for one subject — the persistent state
 * crypto-shredding destroys.
 *
 * The DEK is stored **wrapped** (encrypted under a key-encryption key derived
 * from the application secret), never in the clear. Shredding nulls the wrapped
 * key and sets a tombstone ({@see $shredded}); the row stays so the subject can
 * never be re-keyed, making erasure irreversible.
 */
#[ORM\Entity]
#[ORM\Table(name: 'gdpr_subject_key')]
#[ORM\UniqueConstraint(name: 'uniq_gdpr_subject_key', columns: ['subject_class', 'subject_id'])]
class SubjectKey
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'subject_class', length: 255)]
    private string $subjectClass;

    #[ORM\Column(name: 'subject_id', length: 255)]
    private string $subjectId;

    /**
     * Base64 of the wrapped DEK, or null once shredded.
     */
    #[ORM\Column(name: 'wrapped_key', type: Types::TEXT, nullable: true)]
    private ?string $wrappedKey;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $shredded = false;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'shredded_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $shreddedAt = null;

    public function __construct(string $subjectClass, string $subjectId, string $wrappedKey, \DateTimeImmutable $createdAt)
    {
        $this->id = Uuid::v7()->toRfc4122();
        $this->subjectClass = $subjectClass;
        $this->subjectId = $subjectId;
        $this->wrappedKey = $wrappedKey;
        $this->createdAt = $createdAt;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getSubjectClass(): string
    {
        return $this->subjectClass;
    }

    public function getSubjectId(): string
    {
        return $this->subjectId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getShreddedAt(): ?\DateTimeImmutable
    {
        return $this->shreddedAt;
    }

    public function wrappedKey(): ?string
    {
        return $this->wrappedKey;
    }

    public function isShredded(): bool
    {
        return $this->shredded;
    }

    /**
     * Irreversibly drop the wrapped key and mark the tombstone.
     */
    public function shred(\DateTimeImmutable $at): void
    {
        $this->wrappedKey = null;
        $this->shredded = true;
        $this->shreddedAt = $at;
    }
}
