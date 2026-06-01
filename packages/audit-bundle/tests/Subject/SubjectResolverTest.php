<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Tests\Subject;

use Opus\AuditBundle\Actor\Actor;
use Opus\AuditBundle\Tests\Fixtures\Customer;
use Opus\AuditBundle\Tests\Fixtures\Invoice;
use Opus\AuditBundle\Tests\Support\AuditIntegrationTestCase;

final class SubjectResolverTest extends AuditIntegrationTestCase
{
    public function testResolvesDeclaredDataSubjectAssociation(): void
    {
        $customer = new Customer('Acme');
        $invoice = new Invoice($customer, 'Acme');

        $subjects = $this->services->subjectResolver->resolveForEntity($invoice);

        self::assertSame([Customer::class.'#'.$customer->getId()], $subjects);
    }

    public function testFallsBackToSelfWhenNoDataSubjectDeclared(): void
    {
        // Customer declares no #[DataSubject]; the heuristic keys it on itself.
        $customer = new Customer('Acme');

        $subjects = $this->services->subjectResolver->resolveForEntity($customer);

        self::assertSame([Customer::class.'#'.$customer->getId()], $subjects);
    }

    public function testResolvesActorSubject(): void
    {
        self::assertSame(['actor:user:u-1'], $this->services->subjectResolver->resolveForActor(Actor::user('u-1')));
    }

    public function testSystemAndAnonymousActorsHaveNoSubject(): void
    {
        self::assertSame([], $this->services->subjectResolver->resolveForActor(Actor::system()));
        self::assertSame([], $this->services->subjectResolver->resolveForActor(new Actor(\Opus\AuditBundle\Enum\ActorType::Anonymous)));
    }
}
