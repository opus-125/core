<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Tests\Actor;

use Opus\AuditBundle\Actor\Actor;
use Opus\AuditBundle\Actor\ActorInterface;
use Opus\AuditBundle\Actor\AuditContext;
use Opus\AuditBundle\Actor\SecurityActorResolver;
use Opus\AuditBundle\Enum\ActorType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\AbstractToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;

final class SecurityActorResolverTest extends TestCase
{
    public function testFallsBackToSystemWithoutTokenStorage(): void
    {
        self::assertSame(ActorType::System, new SecurityActorResolver(new AuditContext(), null)->resolve()->getAuditActorType());
    }

    public function testFallsBackToSystemWithoutToken(): void
    {
        self::assertSame(ActorType::System, new SecurityActorResolver(new AuditContext(), new TokenStorage())->resolve()->getAuditActorType());
    }

    public function testResolvesUserFromToken(): void
    {
        $storage = new TokenStorage();
        $storage->setToken($this->tokenFor(new InMemoryUser('alice', null)));

        $actor = new SecurityActorResolver(new AuditContext(), $storage)->resolve();

        self::assertSame(ActorType::User, $actor->getAuditActorType());
        self::assertSame('alice', $actor->getAuditActorId());
    }

    public function testUsesUserDirectlyWhenItImplementsActorInterface(): void
    {
        $user = new class implements UserInterface, ActorInterface {
            public function getAuditActorId(): ?string
            {
                return 'u-42';
            }

            public function getAuditActorLabel(): ?string
            {
                return 'Bob Builder';
            }

            public function getAuditActorType(): ActorType
            {
                return ActorType::User;
            }

            public function getRoles(): array
            {
                return ['ROLE_USER'];
            }

            public function eraseCredentials(): void
            {
            }

            public function getUserIdentifier(): string
            {
                return 'u-42';
            }
        };

        $storage = new TokenStorage();
        $storage->setToken($this->tokenFor($user));

        $actor = new SecurityActorResolver(new AuditContext(), $storage)->resolve();

        self::assertSame($user, $actor, 'the user entity is used as the actor directly');
        self::assertSame('Bob Builder', $actor->getAuditActorLabel());
    }

    public function testDetectsImpersonation(): void
    {
        $storage = new TokenStorage();
        $storage->setToken($this->switchToken(new InMemoryUser('alice', null), $this->tokenFor(new InMemoryUser('admin', null))));

        $actor = new SecurityActorResolver(new AuditContext(), $storage)->resolve();

        self::assertSame('admin', $actor->getAuditActorId());
        self::assertInstanceOf(Actor::class, $actor);
        self::assertSame('alice', $actor->attributes['impersonating'] ?? null);
    }

    public function testAuditContextActorWins(): void
    {
        $storage = new TokenStorage();
        $storage->setToken($this->tokenFor(new InMemoryUser('alice', null)));

        $context = new AuditContext();
        $context->setActor(Actor::cli('nightly-import'));

        $actor = new SecurityActorResolver($context, $storage)->resolve();

        self::assertSame(ActorType::Cli, $actor->getAuditActorType());
        self::assertSame('nightly-import', $actor->getAuditActorId());
    }

    private function tokenFor(UserInterface $user): TokenInterface
    {
        $token = new class extends AbstractToken {};
        $token->setUser($user);

        return $token;
    }

    private function switchToken(UserInterface $user, TokenInterface $original): TokenInterface
    {
        $token = new class($original) extends AbstractToken {
            public function __construct(private readonly TokenInterface $original)
            {
                parent::__construct();
            }

            public function getOriginalToken(): TokenInterface
            {
                return $this->original;
            }
        };
        $token->setUser($user);

        return $token;
    }
}
