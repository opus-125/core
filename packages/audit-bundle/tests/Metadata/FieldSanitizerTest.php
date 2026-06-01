<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Tests\Metadata;

use Opus\AuditBundle\Metadata\FieldSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FieldSanitizerTest extends TestCase
{
    private FieldSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new FieldSanitizer();
    }

    #[DataProvider('sensitiveNames')]
    public function testRecognisesSensitiveNames(string $name): void
    {
        self::assertTrue($this->sanitizer->isSensitiveName($name), $name);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sensitiveNames(): iterable
    {
        yield 'password' => ['password'];
        yield 'plainPassword' => ['plainPassword'];
        yield 'passwd' => ['passwd'];
        yield 'secret' => ['secret'];
        yield 'app_secret' => ['app_secret'];
        yield 'token' => ['token'];
        yield 'apiToken' => ['apiToken'];
        yield 'api_key' => ['api_key'];
        yield 'apiKey' => ['apiKey'];
        yield 'API-KEY' => ['API-KEY'];
        yield 'privateKey' => ['privateKey'];
        yield 'signing_key' => ['signing_key'];
        yield 'credential' => ['credential'];
        yield 'userCredentials' => ['userCredentials'];
    }

    #[DataProvider('safeNames')]
    public function testLeavesOrdinaryNamesAlone(string $name): void
    {
        self::assertFalse($this->sanitizer->isSensitiveName($name), $name);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function safeNames(): iterable
    {
        yield 'status' => ['status'];
        yield 'betrag' => ['betrag'];
        yield 'name' => ['name'];
        yield 'email' => ['email'];
        yield 'key (bare business key)' => ['key'];
        yield 'foreignKey' => ['foreignKey'];
        yield 'sortKey' => ['sortKey'];
        yield 'empty' => [''];
        yield 'monkey (must not match key suffix mid-word)' => ['monkey'];
    }

    public function testMonkeyEdgeCase(): void
    {
        // "monkey" ends with "key" but is a real word — the suffix rule would
        // naively flag it. Documents the known false-positive boundary: we
        // accept that "monkey"/"donkey" style fields are rare in entities and
        // err toward masking is *not* applied here because the allow path keys
        // on exact names. This asserts current behaviour explicitly.
        self::assertFalse($this->sanitizer->isSensitiveName('monkey'));
    }
}
