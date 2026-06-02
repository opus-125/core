<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A citizen — the data subject an {@see Application} concerns. Not itself audited.
 *
 * Holds the per-subject audit key (the "key on the user" pattern): while it is
 * set the applicant's sensitive audit values can be read; anonymising the
 * citizen ({@see anonymize()}) drops the key, which crypto-shreds them.
 */
#[ORM\Entity]
#[ORM\Table(name: 'citizen')]
class Citizen
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: Types::STRING)]
    private string $name;

    /**
     * Base64 of a random 32-byte key, or null once the citizen is anonymised.
     */
    #[ORM\Column(name: 'audit_key', type: Types::STRING, nullable: true)]
    private ?string $auditKey;

    public function __construct(string $name)
    {
        $this->id = Uuid::v7()->toRfc4122();
        $this->name = $name;
        $this->auditKey = base64_encode(random_bytes(32));
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * The raw audit key, or null if anonymised.
     */
    public function getAuditKey(): ?string
    {
        if (null === $this->auditKey) {
            return null;
        }

        return base64_decode($this->auditKey, true) ?: null;
    }

    public function anonymize(): void
    {
        $this->name = 'anonymised';
        $this->auditKey = null;
    }

    public function isAnonymized(): bool
    {
        return null === $this->auditKey;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
