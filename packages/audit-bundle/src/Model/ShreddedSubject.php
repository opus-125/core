<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Model;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Opus\AuditBundle\Repository\ShreddedSubjectRepository;

/**
 * Tombstone marking a crypto-shredded data subject.
 *
 * The default key provider derives keys from the application secret, so it
 * cannot literally throw a single subject's key away; instead a tombstone makes
 * it refuse to (re)derive the key, rendering that subject's encrypted values
 * permanently unreadable. Providers backed by stored random keys can ignore
 * this and delete the key material itself.
 */
#[ORM\Entity(repositoryClass: ShreddedSubjectRepository::class)]
#[ORM\Table(name: 'audit_shredded_subject')]
class ShreddedSubject
{
    #[ORM\Id]
    #[ORM\Column(name: 'subject_id', length: 255)]
    private string $subjectId;

    #[ORM\Column(name: 'shredded_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $shreddedAt;

    public function __construct(string $subjectId, \DateTimeImmutable $shreddedAt)
    {
        $this->subjectId = $subjectId;
        $this->shreddedAt = $shreddedAt;
    }

    public function getSubjectId(): string
    {
        return $this->subjectId;
    }

    public function getShreddedAt(): \DateTimeImmutable
    {
        return $this->shreddedAt;
    }
}
