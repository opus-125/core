<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Tests\Fixtures;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Opus125\AuditBundle\Attribute as Audit;
use Opus125\DataContracts\Attribute as Gdpr;
use Opus125\DataContracts\Erasure\ErasureStrategy;
use Symfony\Component\Uid\Uuid;

/**
 * A subject that is also audited, with a field that is both `#[Audit\Sensitive]`
 * (encrypted in the trail) and `#[Gdpr\PersonalData(erasure: crypto_shred)]`.
 *
 * Used to prove the cross-bundle story: crypto-shredding the subject in the GDPR
 * bundle renders its audited sensitive value unreadable while the audit
 * hash-chain stays valid.
 */
#[ORM\Entity]
#[ORM\Table(name: 'gdpr_fixture_auditable_subject')]
#[Gdpr\DataSubject]
#[Audit\Auditable(stream: 'auditable_subject')]
class AuditableSubject
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: Types::STRING)]
    private string $name;

    #[Audit\Sensitive]
    #[Gdpr\PersonalData(category: 'health', purpose: 'support', basis: 'consent', sensitive: true, erasure: ErasureStrategy::CryptoShred)]
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note;

    public function __construct(string $name, ?string $note = null)
    {
        $this->id = Uuid::v7()->toRfc4122();
        $this->name = $name;
        $this->note = $note;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function setNote(?string $note): void
    {
        $this->note = $note;
    }
}
