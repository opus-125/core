<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Tests\Fixtures;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A mapped entity with no personal-data annotations at all — the registry and
 * locator must ignore it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'gdpr_fixture_plain_tag')]
class PlainTag
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
}
