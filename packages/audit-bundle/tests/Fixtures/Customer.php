<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Tests\Fixtures;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A data subject (not itself audited) referenced by {@see Invoice}.
 */
#[ORM\Entity]
#[ORM\Table(name: 'fixture_customer')]
class Customer
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: Types::STRING)]
    private string $name;

    public function __construct(string $name)
    {
        $this->id = Uuid::v7()->toRfc4122();
        $this->name = $name;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
