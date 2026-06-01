<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Tests\Actor;

use Opus\AuditBundle\Actor\Actor;
use Opus\AuditBundle\Actor\AuditContext;
use Opus\AuditBundle\Actor\SecurityActorResolver;
use Opus\AuditBundle\Enum\ActorType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\AbstractToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class SecurityActorResolverTest extends TestCase
{
    public function testFallsBackToSystemWithoutTokenStorage(): void
    {
        $resolver = new SecurityActorResolver(new AuditContext(), null);

        self::assertSame(ActorType::System, $resolver->resolve()->type);
    }

    public function testFallsBackToSystemWithoutToken(): void
    {
        $resolver = new SecurityActorResolver(new AuditContext(), new TokenStorage());

        self::assertSame(ActorType::System, $resolver->resolve()->type);
    }

    public function testResolvesUserFromToken(): void
    {
        $storage = new TokenStorage();
        $storage->setToken($this->tokenFor(new InMemoryUser('alice', null)));

        $actor = new SecurityActorResolver(new AuditContext(), $storage)->resolve();

        self::assertSame(ActorType::User, $actor->type);
        self::assertSame('alice', $actor->id);
    }

    public function testDetectsImpersonation(): void
    {
        $storage = new TokenStorage();
        $original = $this->tokenFor(new InMemoryUser('admin', null));
        $storage->setToken($this->switchToken(new InMemoryUser('alice', null), $original));

        $actor = new SecurityActorResolver(new AuditContext(), $storage)->resolve();

        // The impersonator (admin) is the responsible actor; the impersonated
        // identity is recorded as context.
        self::assertSame('admin', $actor->id);
        self::assertSame('alice', $actor->attributes['impersonating'] ?? null);
    }

    public function testAuditContextOverrideWins(): void
    {
        $storage = new TokenStorage();
        $storage->setToken($this->tokenFor(new InMemoryUser('alice', null)));

        $context = new AuditContext();
        $context->pushActor(Actor::cli('nightly-import'));

        $actor = new SecurityActorResolver($context, $storage)->resolve();

        self::assertSame(ActorType::Cli, $actor->type);
        self::assertSame('nightly-import', $actor->id);
    }

    private function tokenFor(InMemoryUser $user): TokenInterface
    {
        $token = new class extends AbstractToken {};
        $token->setUser($user);

        return $token;
    }

    private function switchToken(InMemoryUser $user, TokenInterface $original): TokenInterface
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
