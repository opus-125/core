<?php

declare(strict_types=1);

namespace Opus125\AuditBundle\Actor;

use Opus125\AuditBundle\Enum\ActorType;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Default {@see ActorResolverInterface}.
 *
 * Resolution order:
 *  1. an explicit {@see AuditContext} actor (CLI/Messenger via `runAs`);
 *  2. the security token — if the user already implements {@see ActorInterface}
 *     it is used directly; impersonation (a switch-user token) credits the
 *     impersonator while noting whom they acted as;
 *  3. otherwise a system actor.
 *
 * Impersonation is detected by duck-typing `getOriginalToken()`, so the bundle
 * need not depend on symfony/security-http.
 */
final class SecurityActorResolver implements ActorResolverInterface
{
    public function __construct(
        private readonly AuditContext $context,
        private readonly ?TokenStorageInterface $tokenStorage = null,
    ) {
    }

    public function resolve(): ActorInterface
    {
        $override = $this->context->currentActor();
        if (null !== $override) {
            return $override;
        }

        $token = $this->tokenStorage?->getToken();
        if (null !== $token) {
            return $this->fromToken($token);
        }

        return Actor::system();
    }

    private function fromToken(TokenInterface $token): ActorInterface
    {
        if (method_exists($token, 'getOriginalToken')) {
            $original = $token->getOriginalToken();
            if ($original instanceof TokenInterface) {
                $impersonator = $original->getUser();
                if (null !== $impersonator) {
                    $id = $impersonator->getUserIdentifier();

                    return new Actor(ActorType::User, $id, $id, ['impersonating' => $token->getUser()?->getUserIdentifier() ?? '']);
                }
            }
        }

        $user = $token->getUser();
        if ($user instanceof ActorInterface) {
            return $user;
        }

        if (null !== $user) {
            $id = $user->getUserIdentifier();

            return Actor::user($id, $id);
        }

        return new Actor(ActorType::Anonymous);
    }
}
