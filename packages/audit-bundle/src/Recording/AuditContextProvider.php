<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Recording;

use Opus\AuditBundle\Actor\Actor;
use Opus\AuditBundle\Actor\AuditContext;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Assembles the circumstances recorded alongside a change.
 *
 * Splits the result into a *public* part (route, method, correlation id,
 * reason, impersonation marker, app annotations) and a *sensitive* part
 * (client IP, user-agent) that the recorder encrypts under the actor's subject
 * key — the acting person's network identifiers are personal data too.
 *
 * The {@see RequestStack} is optional, so the provider works unchanged in CLI
 * and Messenger contexts where there is no request.
 */
final class AuditContextProvider
{
    public function __construct(
        private readonly AuditContext $context,
        private readonly ?RequestStack $requestStack = null,
    ) {
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, scalar>} [public, sensitive]
     */
    public function gather(Actor $actor): array
    {
        $public = $this->context->toArray();

        foreach ($actor->attributes as $key => $value) {
            if ('' !== (string) $value) {
                $public[$key] = $value;
            }
        }

        $sensitive = [];

        $request = $this->requestStack?->getCurrentRequest();
        if (null !== $request) {
            $route = $request->attributes->get('_route');
            if (\is_string($route)) {
                $public['route'] = $route;
            }
            $public['method'] = $request->getMethod();

            $ip = $request->getClientIp();
            if (null !== $ip) {
                $sensitive['ip'] = $ip;
            }
            $userAgent = $request->headers->get('User-Agent');
            if (null !== $userAgent) {
                $sensitive['user_agent'] = $userAgent;
            }
        }

        return [$public, $sensitive];
    }
}
