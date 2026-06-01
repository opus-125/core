<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Opus\AuditBundle\Attribute\Auditable;
use Opus\AuditBundle\Attribute\AuditIgnore;
use Opus\AuditBundle\Attribute\DataSubject;
use Opus\AuditBundle\Attribute\Retention;
use Opus\AuditBundle\Attribute\Sensitive;
use Symfony\Component\Uid\Uuid;

/**
 * An administrative application (Antrag) — the audited aggregate of the demo.
 *
 *  - audited into the `antrag` stream;
 *  - kept for 10 years (GoBD/BAO-style retention);
 *  - the applicant is the data subject for crypto-shredding;
 *  - the free-text note is sensitive personal data (encrypted, shred-able);
 *  - the internal token is never logged.
 */
#[ORM\Entity]
#[ORM\Table(name: 'antrag')]
#[Auditable(stream: 'antrag')]
#[Retention('10 years')]
class Antrag
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: Types::STRING)]
    private string $betreff;

    #[ORM\Column(type: Types::STRING)]
    private string $status = 'eingebracht';

    #[Sensitive]
    #[ORM\Column(type: Types::TEXT)]
    private string $notiz = '';

    #[DataSubject]
    #[ORM\ManyToOne(targetEntity: Buerger::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Buerger $antragsteller;

    #[AuditIgnore]
    #[ORM\Column(type: Types::STRING)]
    private string $internerToken = 'tok-000';

    public function __construct(Buerger $antragsteller, string $betreff)
    {
        $this->id = Uuid::v7()->toRfc4122();
        $this->antragsteller = $antragsteller;
        $this->betreff = $betreff;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getBetreff(): string
    {
        return $this->betreff;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getNotiz(): string
    {
        return $this->notiz;
    }

    public function setNotiz(string $notiz): void
    {
        $this->notiz = $notiz;
    }

    public function getAntragsteller(): Buerger
    {
        return $this->antragsteller;
    }

    /**
     * Demonstrates #[AuditIgnore]: the application uses this value but it is
     * never written to the audit trail.
     */
    public function getInternerToken(): string
    {
        return $this->internerToken;
    }
}
