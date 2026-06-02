<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Opus125\DataContracts\Attribute as Gdpr;
use Opus125\DataContracts\Erasure\ErasureStrategy;
use Symfony\Component\Uid\Uuid;

/**
 * A citizen — the data subject an {@see Application} concerns.
 *
 * Marked `#[DataSubject]`: the GDPR bundle resolves an application back to its
 * applicant, and erasing the citizen (`gdpr:erase`) pseudonymises their name and
 * crypto-shreds their key — which also redacts the applicant's sensitive audit
 * values, since the audit key provider is bridged to the GDPR key store.
 */
#[ORM\Entity]
#[ORM\Table(name: 'citizen')]
#[Gdpr\DataSubject]
class Citizen
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[Gdpr\PersonalData(category: 'name', purpose: 'registry', basis: 'legal_obligation', erasure: ErasureStrategy::Pseudonymize)]
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
