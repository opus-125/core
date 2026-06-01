<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Recording;

use Opus\AuditBundle\Crypto\CryptoShredder;

/**
 * Applies crypto-shredding to actor personal data.
 *
 * The acting person's network identifiers (IP, user-agent) and display label
 * are personal data, so when the actor is an identifiable subject they are
 * encrypted under that subject's key — and thus erased together with everything
 * else about them. Without an actor subject (system/anonymous) they are stored
 * as-is. Shared by the entry recorder and the event recorder.
 */
final class ActorContextEncryptor
{
    public function __construct(
        private readonly CryptoShredder $shredder,
    ) {
    }

    /**
     * @param array<string, mixed>  $publicContext
     * @param array<string, scalar> $sensitiveContext
     * @param list<string>          $actorSubjects
     *
     * @return array{0: array<string, mixed>, 1: string|null} [merged context, actor label]
     */
    public function apply(array $publicContext, array $sensitiveContext, ?string $label, array $actorSubjects): array
    {
        if ([] === $actorSubjects) {
            return [array_merge($publicContext, $sensitiveContext), $label];
        }

        foreach ($sensitiveContext as $key => $value) {
            $publicContext[$key] = $this->shredder->encryptValue($value, $actorSubjects);
        }

        if (null !== $label) {
            $label = (string) json_encode($this->shredder->encryptValue($label, $actorSubjects), \JSON_THROW_ON_ERROR);
        }

        return [$publicContext, $label];
    }
}
