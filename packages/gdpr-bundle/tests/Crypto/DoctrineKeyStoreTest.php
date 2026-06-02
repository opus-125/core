<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Tests\Crypto;

use Opus125\DataContracts\Subject\SubjectReference;
use Opus125\GdprBundle\Crypto\Cipher;
use Opus125\GdprBundle\Crypto\DoctrineKeyStore;
use Opus125\GdprBundle\Crypto\KeyStoreInterface;
use Opus125\GdprBundle\Tests\Fixtures\Contact;
use Opus125\GdprBundle\Tests\Support\DatabaseTestCase;
use Symfony\Component\Clock\MockClock;

final class DoctrineKeyStoreTest extends DatabaseTestCase
{
    private function store(): KeyStoreInterface
    {
        return new DoctrineKeyStore(self::$em, new Cipher(), new MockClock(), 'test-secret');
    }

    public function testMintsAndReturnsStableKey(): void
    {
        $store = $this->store();
        $subject = new SubjectReference(Contact::class, 'subject-1');

        self::assertNull($store->keyFor($subject));

        $key = $store->ensureKey($subject);
        self::assertNotNull($key);
        self::assertSame(Cipher::KEY_BYTES, \strlen($key));

        self::assertSame($key, $store->ensureKey($subject), 'ensureKey is idempotent');
        self::assertSame($key, $store->keyFor($subject), 'the key round-trips through wrapping');
        self::assertFalse($store->isShredded($subject));
    }

    public function testKeyIsWrappedAtRest(): void
    {
        $store = $this->store();
        $subject = new SubjectReference(Contact::class, 'subject-1');
        $key = $store->ensureKey($subject);
        self::assertNotNull($key);

        /** @var string|false $wrapped */
        $wrapped = self::$connection->createQueryBuilder()
            ->select('wrapped_key')->from('gdpr_subject_key')
            ->where('subject_id = :id')->setParameter('id', 'subject-1')
            ->fetchOne();

        self::assertIsString($wrapped);
        self::assertStringNotContainsString($key, (string) base64_decode($wrapped, true), 'the raw DEK never appears at rest');
    }

    public function testShredIsIrreversible(): void
    {
        $store = $this->store();
        $subject = new SubjectReference(Contact::class, 'subject-1');
        $store->ensureKey($subject);

        $store->shred($subject);

        self::assertTrue($store->isShredded($subject));
        self::assertNull($store->keyFor($subject));
        self::assertNull($store->ensureKey($subject), 'a shredded subject is never re-keyed');
    }

    public function testShredBeforeAnyKeyWritesTombstone(): void
    {
        $store = $this->store();
        $subject = new SubjectReference(Contact::class, 'never-keyed');

        $store->shred($subject);

        self::assertTrue($store->isShredded($subject));
        self::assertNull($store->ensureKey($subject));
    }
}
