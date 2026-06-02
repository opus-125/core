<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Tests\Subject;

use Opus125\GdprBundle\Registry\PersonalDataRegistry;
use Opus125\GdprBundle\Subject\SubjectResolver;
use Opus125\GdprBundle\Tests\Fixtures\Contact;
use Opus125\GdprBundle\Tests\Fixtures\CyclicNode;
use Opus125\GdprBundle\Tests\Fixtures\Household;
use Opus125\GdprBundle\Tests\Fixtures\Order;
use Opus125\GdprBundle\Tests\Fixtures\OrderLine;
use Opus125\GdprBundle\Tests\Support\DatabaseTestCase;

final class SubjectResolverTest extends DatabaseTestCase
{
    private SubjectResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new SubjectResolver(self::$em, new PersonalDataRegistry());
    }

    /**
     * @param iterable<object> $persist
     */
    private function persist(iterable $persist): void
    {
        foreach ($persist as $entity) {
            self::$em->persist($entity);
        }
        self::$em->flush();
        self::$em->clear();
    }

    /**
     * @return list<string>
     */
    private function tokens(object $entity): array
    {
        return array_map(strval(...), $this->resolver->resolve($entity));
    }

    public function testDirectSubject(): void
    {
        $contact = new Contact('Maria');
        $this->persist([$contact]);

        self::assertSame([Contact::class.'#'.$contact->getId()], $this->tokens(self::$em->find(Contact::class, $contact->getId())));
    }

    public function testOneHopLink(): void
    {
        $contact = new Contact('Maria');
        $order = new Order($contact, 'Hauptstr 1');
        $this->persist([$contact, $order]);

        self::assertSame([Contact::class.'#'.$contact->getId()], $this->tokens(self::$em->find(Order::class, $order->getId())));
    }

    public function testMultiLevelLink(): void
    {
        $contact = new Contact('Maria');
        $order = new Order($contact, 'Hauptstr 1');
        $line = new OrderLine($order, 'Happy birthday');
        $this->persist([$contact, $order, $line]);

        self::assertSame([Contact::class.'#'.$contact->getId()], $this->tokens(self::$em->find(OrderLine::class, $line->getId())));
    }

    public function testSharedDataResolvesEverySubject(): void
    {
        $a = new Contact('Maria');
        $b = new Contact('Josef');
        $household = new Household($a, $b, 'Hauptstr 1');
        $this->persist([$a, $b, $household]);

        $tokens = $this->tokens(self::$em->find(Household::class, $household->getId()));
        sort($tokens);
        $expected = [Contact::class.'#'.$a->getId(), Contact::class.'#'.$b->getId()];
        sort($expected);

        self::assertSame($expected, $tokens);
    }

    public function testOrphanLinkResolvesToNothing(): void
    {
        $order = new Order(null, 'Hauptstr 1');
        $this->persist([$order]);

        self::assertSame([], $this->tokens(self::$em->find(Order::class, $order->getId())));
    }

    public function testCycleTerminatesWithNoSubject(): void
    {
        $a = new CyclicNode('a');
        $b = new CyclicNode('b');
        $a->linkTo($b);
        $b->linkTo($a);
        $this->persist([$a, $b]);

        self::assertSame([], $this->tokens(self::$em->find(CyclicNode::class, $a->getId())));
    }
}
