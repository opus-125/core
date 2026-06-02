<?php

declare(strict_types=1);

namespace Opus125\AuditBundle\Tests\Fixtures;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Opus125\AuditBundle\Attribute\Auditable;
use Opus125\AuditBundle\Attribute\AuditableWorkflow;
use Symfony\Component\Uid\Uuid;

/**
 * An audited aggregate whose `status` column is driven by a Symfony Workflow.
 *
 * `#[AuditableWorkflow]` opts its transitions into the trail; `marking: 'status'`
 * tells the recorder which field the workflow owns, so a transition is logged
 * once — as a `transition` entry — rather than also as a `status` field update.
 */
#[ORM\Entity]
#[ORM\Table(name: 'fixture_article')]
#[Auditable(stream: 'article')]
#[AuditableWorkflow(marking: 'status')]
class Article
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: Types::STRING)]
    private string $status = 'draft';

    #[ORM\Column(type: Types::STRING)]
    private string $title;

    public function __construct(string $title)
    {
        $this->id = Uuid::v7()->toRfc4122();
        $this->title = $title;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }
}
