<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Metadata;

/**
 * Safety net that flags field names that *look* secret even when the developer
 * forgot to annotate them.
 *
 * This is a backstop, not a replacement for {@see \Opus\AuditBundle\Attribute\AuditIgnore}
 * / {@see \Opus\AuditBundle\Attribute\Sensitive}: it catches the common
 * "oops, plaintext password in the audit log" mistake by recognising names like
 * `password`, `token`, `secret`, `apiKey`, `private_key`. Matching is done on a
 * normalised form so `apiKey`, `api_key` and `API-KEY` are treated alike.
 *
 * A flagged value is masked (replaced by a fixed placeholder) rather than
 * encrypted: the heuristic cannot know which subject a value belongs to, and a
 * suspected secret has no place in the log even as ciphertext.
 */
final class FieldSanitizer
{
    public const string MASK = '__opus_audit_masked__';

    /**
     * Substrings that, if present in the normalised field name, mark it secret.
     *
     * @var list<string>
     */
    private const array DENY_SUBSTRINGS = [
        'password',
        'passwd',
        'secret',
        'token',
        'apikey',
        'privatekey',
        'credential',
    ];

    /**
     * Normalised names that are explicitly safe even though they end in "key",
     * so ordinary structural fields are never masked by accident.
     *
     * @var list<string>
     */
    private const array ALLOW_EXACT = [
        'key',
        'foreignkey',
        'primarykey',
        'sortkey',
        'partitionkey',
        'routingkey',
    ];

    /**
     * Generic "…key" with a word boundary before "key": snake/kebab (`_key`,
     * `-key`, any case) or camelCase (`…Key`). The boundary requirement keeps
     * real words such as "monkey"/"donkey" from being flagged.
     *
     * @var list<string>
     */
    private const array KEY_SUFFIX_PATTERNS = [
        '/[_-]key$/i',
        '/[a-z0-9]Key$/',
    ];

    public function isSensitiveName(string $fieldName): bool
    {
        $normalised = $this->normalise($fieldName);

        if ('' === $normalised) {
            return false;
        }

        if (\in_array($normalised, self::ALLOW_EXACT, true)) {
            return false;
        }

        foreach (self::DENY_SUBSTRINGS as $needle) {
            if (str_contains($normalised, $needle)) {
                return true;
            }
        }

        foreach (self::KEY_SUFFIX_PATTERNS as $pattern) {
            if (1 === preg_match($pattern, $fieldName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lower-case the name and strip non-alphanumeric separators so that
     * `api_key`, `apiKey` and `API-KEY` normalise to `apikey`.
     */
    private function normalise(string $fieldName): string
    {
        return strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '', $fieldName));
    }
}
