<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Tests\Fixtures;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Many-to-many member used to exercise collection-change capture.
 */
#[ORM\Entity]
#[ORM\Table(name: 'fixture_tag')]
class Tag
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

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

    public function getLabel(): string
    {
        return $this->label;
    }

    public function __toString(): string
    {
        return $this->label;
    }
}
