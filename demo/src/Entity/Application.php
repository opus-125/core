<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Opus125\AuditBundle\Attribute as Audit;
use Opus125\DataContracts\Attribute as Gdpr;
use Opus125\DataContracts\Erasure\ErasureStrategy;
use Symfony\Component\Uid\Uuid;

/**
 * An administrative application — the audited aggregate of the demo.
 *
 * Demonstrates the attribute surface using the Doctrine-style alias import:
 * `use Opus125\AuditBundle\Attribute as Audit;` then `#[Audit\Auditable]`, …
 */
#[ORM\Entity]
#[ORM\Table(name: 'application')]
#[Audit\Auditable(stream: 'application')]
#[Audit\Retention('10 years')]
class Application
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: Types::STRING)]
    private string $subject;

    #[ORM\Column(type: Types::STRING)]
    private string $status = 'submitted';

    #[Audit\Sensitive]
    #[Gdpr\PersonalData(category: 'case-note', purpose: 'case', basis: 'consent', sensitive: true, erasure: ErasureStrategy::CryptoShred)]
    #[ORM\Column(type: Types::TEXT)]
    private string $note = '';

    #[Gdpr\SubjectLink(Citizen::class)]
    #[ORM\ManyToOne(targetEntity: Citizen::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Citizen $applicant;

    #[Audit\AuditIgnore]
    #[ORM\Column(type: Types::STRING)]
    private string $internalToken = 'tok-000';

    public function __construct(Citizen $applicant, string $subject)
    {
        $this->id = Uuid::v7()->toRfc4122();
        $this->applicant = $applicant;
        $this->subject = $subject;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getNote(): string
    {
        return $this->note;
    }

    public function setNote(string $note): void
    {
        $this->note = $note;
    }

    public function getApplicant(): Citizen
    {
        return $this->applicant;
    }

    public function getInternalToken(): string
    {
        return $this->internalToken;
    }
}
