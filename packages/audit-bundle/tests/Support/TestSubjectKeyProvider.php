<?php

declare(strict_types=1);

namespace Opus125\AuditBundle\Tests\Support;

use Opus125\AuditBundle\Crypto\SubjectKeyProviderInterface;

/**
 * In-memory key provider for tests: derives a key per subject, and lets a test
 * "shred" a subject so {@see keyFor()} returns null thereafter (the way a real
 * project would by anonymising/destroying a stored key).
 */
final class TestSubjectKeyProvider implements SubjectKeyProviderInterface
{
    /**
     * @var array<string, true>
     */
    private array $shredded = [];

    public function keyFor(string $entityClass, string $entityId): ?string
    {
        $reference = $entityClass.'#'.$entityId;
        if (isset($this->shredded[$reference])) {
            return null;
        }

        return hash_hkdf('sha256', 'test-secret', 32, $reference);
    }

    public function shred(string $entityClass, string $entityId): void
    {
        $this->shredded[$entityClass.'#'.$entityId] = true;
    }
}
