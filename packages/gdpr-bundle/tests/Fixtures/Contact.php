<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Tests\Fixtures;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Opus125\DataContracts\Attribute as Gdpr;
use Opus125\DataContracts\Erasure\ErasureStrategy;
use Symfony\Component\Uid\Uuid;

/**
 * The data subject of the fixture domain: a contact, with one field per erasure
 * strategy.
 */
#[ORM\Entity]
#[ORM\Table(name: 'gdpr_fixture_contact')]
#[Gdpr\DataSubject]
class Contact
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[Gdpr\PersonalData(category: 'name', purpose: 'crm', basis: 'contract', erasure: ErasureStrategy::Pseudonymize)]
    #[ORM\Column(type: Types::STRING)]
    private string $name;

    #[Gdpr\PersonalData(category: 'contact', purpose: 'crm', basis: 'contract', erasure: ErasureStrategy::Nullify)]
    #[ORM\Column(type: Types::STRING, nullable: true)]
    private ?string $email;

    #[Gdpr\PersonalData(category: 'health', purpose: 'support', basis: 'consent', sensitive: true, erasure: ErasureStrategy::CryptoShred)]
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note;

    public function __construct(string $name, ?string $email = null, ?string $note = null)
    {
        $this->id = Uuid::v7()->toRfc4122();
        $this->name = $name;
        $this->email = $email;
        $this->note = $note;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
