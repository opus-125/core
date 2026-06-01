<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A citizen — the data subject an {@see Antrag} concerns. Not itself audited.
 */
#[ORM\Entity]
#[ORM\Table(name: 'buerger')]
class Buerger
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
