<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Tests\Fixtures;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Opus125\DataContracts\Attribute as Gdpr;
use Opus125\DataContracts\Erasure\ErasureStrategy;
use Symfony\Component\Uid\Uuid;

/**
 * Personal data on a non-subject entity, linked to its subject — the basic
 * one-hop resolution case. A nullable link covers the orphan case.
 */
#[ORM\Entity]
#[ORM\Table(name: 'gdpr_fixture_order')]
class Order
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[Gdpr\SubjectLink(Contact::class)]
    #[ORM\ManyToOne(targetEntity: Contact::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Contact $contact;

    #[Gdpr\PersonalData(category: 'address', purpose: 'shipping', basis: 'contract', erasure: ErasureStrategy::Nullify)]
    #[ORM\Column(type: Types::STRING)]
    private string $shippingAddress;

    public function __construct(?Contact $contact, string $shippingAddress)
    {
        $this->id = Uuid::v7()->toRfc4122();
        $this->contact = $contact;
        $this->shippingAddress = $shippingAddress;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getContact(): ?Contact
    {
        return $this->contact;
    }

    public function getShippingAddress(): string
    {
        return $this->shippingAddress;
    }
}
