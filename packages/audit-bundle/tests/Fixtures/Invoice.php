<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Tests\Fixtures;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Opus\AuditBundle\Attribute\Auditable;
use Opus\AuditBundle\Attribute\AuditIgnore;
use Opus\AuditBundle\Attribute\Retention;
use Opus\AuditBundle\Attribute\Sensitive;
use Symfony\Component\Uid\Uuid;

/**
 * An audited aggregate root exercising every Spine field attribute.
 */
#[ORM\Entity]
#[ORM\Table(name: 'fixture_invoice')]
#[Auditable(stream: 'rechnung')]
#[Retention('10 years')]
class Invoice
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: Types::STRING)]
    private string $status = 'draft';

    #[ORM\Column(type: Types::INTEGER)]
    private int $amount = 0;

    #[Sensitive]
    #[ORM\Column(type: Types::STRING)]
    private string $customerName;

    #[ORM\ManyToOne(targetEntity: Customer::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Customer $customer;

    #[AuditIgnore]
    #[ORM\Column(type: Types::STRING)]
    private string $internalToken = 'tok-initial';

    #[ORM\Column(name: 'api_key', type: Types::STRING, nullable: true)]
    private ?string $apiKey = null;

    /**
     * @var Collection<int, Tag>
     */
    #[ORM\ManyToMany(targetEntity: Tag::class)]
    #[ORM\JoinTable(name: 'fixture_invoice_tag')]
    private Collection $tags;

    public function __construct(Customer $customer, string $customerName)
    {
        $this->id = Uuid::v7()->toRfc4122();
        $this->customer = $customer;
        $this->customerName = $customerName;
        $this->tags = new ArrayCollection();
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

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function setAmount(int $amount): void
    {
        $this->amount = $amount;
    }

    public function getCustomerName(): string
    {
        return $this->customerName;
    }

    public function setCustomerName(string $customerName): void
    {
        $this->customerName = $customerName;
    }

    public function getCustomer(): Customer
    {
        return $this->customer;
    }

    public function setInternalToken(string $token): void
    {
        $this->internalToken = $token;
    }

    public function setApiKey(?string $apiKey): void
    {
        $this->apiKey = $apiKey;
    }

    /**
     * @return Collection<int, Tag>
     */
    public function getTags(): Collection
    {
        return $this->tags;
    }

    public function addTag(Tag $tag): void
    {
        if (!$this->tags->contains($tag)) {
            $this->tags->add($tag);
        }
    }

    public function removeTag(Tag $tag): void
    {
        $this->tags->removeElement($tag);
    }
}
