<?php

declare(strict_types=1);

namespace Opus125\AuditBundle\Tests\Workflow;

use Opus125\AuditBundle\Actor\Actor;
use Opus125\AuditBundle\Enum\ActorType;
use Opus125\AuditBundle\Model\AuditEntry;
use Opus125\AuditBundle\Repository\AuditEntryRepository;
use Opus125\AuditBundle\Tests\Fixtures\Article;
use Opus125\AuditBundle\Tests\Support\AuditIntegrationTestCase;
use Opus125\AuditBundle\Workflow\WorkflowAuditSubscriber;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Workflow\DefinitionBuilder;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\Exception\NotEnabledTransitionException;
use Symfony\Component\Workflow\MarkingStore\MethodMarkingStore;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\Workflow;

/**
 * The acceptance gate for automatic workflow-transition auditing:
 * one entry per applied transition, none for rejected guards, no double logging
 * with the field capture, and a correctly resolved actor.
 */
final class WorkflowTransitionAuditTest extends AuditIntegrationTestCase
{
    protected static function fixtureEntities(): array
    {
        return [Article::class];
    }

    public function testAppliedTransitionProducesExactlyOneChainedTransitionEntry(): void
    {
        $article = $this->createArticle();
        $workflow = $this->workflow();

        $workflow->apply($article, 'submit');
        self::$em->flush();

        $entries = $this->entries();
        self::assertCount(2, $entries, 'one create entry, then exactly one transition entry');

        $transition = $entries[1];
        self::assertSame('transition', $transition->getAction());
        self::assertSame('article', $transition->getStream(), 'chains into the entity stream');
        self::assertSame(2, $transition->getSequenceNo());
        self::assertSame($entries[0]->getHash(), $transition->getPreviousHash(), 'links to the previous entry');
        self::assertSame($article->getId(), $transition->getEntityId());
        self::assertSame(Article::class, $transition->getEntityClass());

        self::assertEqualsCanonicalizing(['old' => ['draft'], 'new' => ['review']], $transition->getChanges()['place']);
        self::assertSame('article_flow', $transition->getContext()['workflow']);
        self::assertSame('submit', $transition->getContext()['transition']);

        self::assertTrue($this->repository()->verify('article')->valid, 'the chain stays intact');
    }

    public function testRejectedGuardRecordsNothing(): void
    {
        $article = $this->createArticle();

        $dispatcher = $this->dispatcher();
        $dispatcher->addListener(
            'workflow.article_flow.guard.submit',
            static fn (GuardEvent $event) => $event->setBlocked(true, 'not allowed in the test'),
        );
        $workflow = $this->workflow($dispatcher);

        // A pure can() probe fires guard events — these must never be audited.
        self::assertFalse($workflow->can($article, 'submit'));

        try {
            $workflow->apply($article, 'submit');
            self::fail('the blocked transition should not apply');
        } catch (NotEnabledTransitionException) {
            // expected
        }
        self::$em->flush();

        self::assertSame(1, $this->countEntries(), 'only the create entry exists; no transition was recorded');
        self::assertSame('draft', $article->getStatus(), 'the marking did not move');
    }

    public function testMarkingChangeIsNotLoggedTwice(): void
    {
        $article = $this->createArticle();
        $workflow = $this->workflow();

        $workflow->apply($article, 'submit');
        self::$em->flush();

        $afterCreate = \array_slice($this->entries(), 1);
        self::assertCount(1, $afterCreate, 'the marking move produced no separate field-update entry');
        self::assertSame('transition', $afterCreate[0]->getAction());
        self::assertArrayNotHasKey('status', $afterCreate[0]->getChanges(), 'the marking field is not duplicated as a field diff');
    }

    public function testConcurrentNonMarkingChangeStaysFieldAudited(): void
    {
        $article = $this->createArticle();
        $workflow = $this->workflow();

        // Same flush: a workflow transition AND an ordinary field edit.
        $workflow->apply($article, 'submit');
        $article->setTitle('Renamed');
        self::$em->flush();

        $entries = \array_slice($this->entries(), 1); // drop the create entry
        $byAction = [];
        foreach ($entries as $entry) {
            $byAction[$entry->getAction()] = $entry;
        }

        self::assertArrayHasKey('transition', $byAction);
        self::assertArrayHasKey('update', $byAction, 'the non-marking edit is still captured');
        self::assertArrayHasKey('title', $byAction['update']->getChanges());
        self::assertArrayNotHasKey('status', $byAction['update']->getChanges(), 'status belongs to the transition entry only');
    }

    public function testActorIsResolvedForTheTransition(): void
    {
        $article = $this->createArticle();
        $workflow = $this->workflow();

        $this->services->auditContext->runAs(Actor::cli('editor-bot'), static function () use ($workflow, $article): void {
            $workflow->apply($article, 'submit');
            self::$em->flush();
        });

        $transition = $this->lastEntry();
        self::assertSame('transition', $transition->getAction());
        self::assertSame(ActorType::Cli, $transition->getActorType());
        self::assertSame('editor-bot', $transition->getActorId());
    }

    public function testRolledBackTransitionLeavesNoEntry(): void
    {
        $article = $this->createArticle();
        $workflow = $this->workflow();

        self::$connection->beginTransaction();
        $workflow->apply($article, 'submit');
        self::$em->flush();
        self::$connection->rollBack();
        self::$em->clear();

        self::assertSame(1, $this->countEntries(), 'a rolled-back transition must leave no audit row');
    }

    private function createArticle(string $title = 'Hello'): Article
    {
        $article = new Article($title);
        self::$em->persist($article);
        self::$em->flush();

        return $article;
    }

    private function dispatcher(): EventDispatcher
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new WorkflowAuditSubscriber($this->services->reader, $this->services->workflowBuffer));

        return $dispatcher;
    }

    private function workflow(?EventDispatcher $dispatcher = null): Workflow
    {
        $definition = new DefinitionBuilder()
            ->addPlaces(['draft', 'review', 'published'])
            ->addTransition(new Transition('submit', 'draft', 'review'))
            ->addTransition(new Transition('publish', 'review', 'published'))
            ->build();

        return new Workflow($definition, new MethodMarkingStore(true, 'status'), $dispatcher ?? $this->dispatcher(), 'article_flow');
    }

    /**
     * @return list<AuditEntry>
     */
    private function entries(): array
    {
        self::$em->clear();

        return self::$em->getRepository(AuditEntry::class)->findBy([], ['sequenceNo' => 'ASC']);
    }

    private function lastEntry(): AuditEntry
    {
        $entries = $this->entries();

        return $entries[array_key_last($entries)];
    }

    private function repository(): AuditEntryRepository
    {
        self::$em->clear();
        /** @var AuditEntryRepository $repository */
        $repository = self::$em->getRepository(AuditEntry::class);

        return $repository;
    }

    private function countEntries(): int
    {
        return (int) self::$connection->fetchOne('SELECT COUNT(*) FROM audit_entry');
    }
}
