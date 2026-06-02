<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Tests\Fixtures;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Opus125\DataContracts\Attribute as Gdpr;
use Opus125\DataContracts\Erasure\ErasureStrategy;
use Symfony\Component\Uid\Uuid;

/**
 * Shared data: a household whose address line is reachable from *two* subjects
 * (its members). Erasing one member must not strip the shared address.
 */
#[ORM\Entity]
#[ORM\Table(name: 'gdpr_fixture_household')]
class Household
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[Gdpr\SubjectLink(Contact::class)]
    #[ORM\ManyToOne(targetEntity: Contact::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Contact $primary;

    #[Gdpr\SubjectLink(Contact::class)]
    #[ORM\ManyToOne(targetEntity: Contact::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Contact $secondary;

    #[Gdpr\PersonalData(category: 'address', purpose: 'crm', basis: 'contract', erasure: ErasureStrategy::Nullify)]
    #[ORM\Column(type: Types::STRING)]
    private string $addressLine;

    public function __construct(Contact $primary, ?Contact $secondary, string $addressLine)
    {
        $this->id = Uuid::v7()->toRfc4122();
        $this->primary = $primary;
        $this->secondary = $secondary;
        $this->addressLine = $addressLine;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getAddressLine(): string
    {
        return $this->addressLine;
    }
}
