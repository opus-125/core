<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Tests\Fixtures;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Opus125\DataContracts\Attribute as Gdpr;
use Symfony\Component\Uid\Uuid;

/**
 * A self-linking node that never reaches a subject — used to prove cycle
 * detection terminates and resolves to no subject (conservative).
 */
#[ORM\Entity]
#[ORM\Table(name: 'gdpr_fixture_cyclic_node')]
class CyclicNode
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[Gdpr\SubjectLink(self::class)]
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?CyclicNode $peer = null;

    #[Gdpr\PersonalData(category: 'label', purpose: 'misc', basis: 'contract')]
    #[ORM\Column(type: Types::STRING)]
    private string $label;

    public function __construct(string $label)
    {
        $this->id = Uuid::v7()->toRfc4122();
        $this->label = $label;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function linkTo(self $peer): void
    {
        $this->peer = $peer;
    }
}
