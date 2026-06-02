<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Tests\Fixtures;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Opus125\DataContracts\Attribute as Gdpr;
use Opus125\DataContracts\Erasure\ErasureStrategy;
use Symfony\Component\Uid\Uuid;

/**
 * Two hops from the subject (`OrderLine → Order → Contact`) — the multi-level
 * resolution case.
 */
#[ORM\Entity]
#[ORM\Table(name: 'gdpr_fixture_order_line')]
class OrderLine
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[Gdpr\SubjectLink(Order::class)]
    #[ORM\ManyToOne(targetEntity: Order::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Order $order;

    #[Gdpr\PersonalData(category: 'message', purpose: 'shipping', basis: 'contract', erasure: ErasureStrategy::Nullify)]
    #[ORM\Column(type: Types::STRING, nullable: true)]
    private ?string $giftMessage;

    public function __construct(Order $order, ?string $giftMessage = null)
    {
        $this->id = Uuid::v7()->toRfc4122();
        $this->order = $order;
        $this->giftMessage = $giftMessage;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getOrder(): Order
    {
        return $this->order;
    }

    public function getGiftMessage(): ?string
    {
        return $this->giftMessage;
    }
}
