<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Recording;

use Opus\AuditBundle\Actor\Actor;
use Opus\AuditBundle\Actor\ActorInterface;
use Opus\AuditBundle\Actor\AuditContext;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Assembles the circumstances recorded alongside a change: the ambient context
 * bag (correlation id, reason, …), the current request's route/method/IP/
 * user-agent when available, and any non-identifying actor attributes (e.g. an
 * impersonation marker).
 *
 * The {@see RequestStack} is optional, so this works unchanged in CLI and
 * Messenger contexts.
 */
final class AuditContextProvider
{
    public function __construct(
        private readonly AuditContext $context,
        private readonly ?RequestStack $requestStack = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function gather(ActorInterface $actor): array
    {
        $context = $this->context->toArray();

        if ($actor instanceof Actor) {
            foreach ($actor->attributes as $key => $value) {
                if ('' !== (string) $value) {
                    $context[$key] = $value;
                }
            }
        }

        $request = $this->requestStack?->getCurrentRequest();
        if (null !== $request) {
            $route = $request->attributes->get('_route');
            if (\is_string($route)) {
                $context['route'] = $route;
            }
            $context['method'] = $request->getMethod();
            $ip = $request->getClientIp();
            if (null !== $ip) {
                $context['ip'] = $ip;
            }
            $userAgent = $request->headers->get('User-Agent');
            if (null !== $userAgent) {
                $context['user_agent'] = $userAgent;
            }
        }

        return $context;
    }
}
