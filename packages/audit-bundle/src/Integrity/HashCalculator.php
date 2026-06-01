<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Integrity;

/**
 * Computes the links of the audit trail's linear hash-chain.
 *
 * Each audit entry carries `hash = sha256( canonical_json(entry) || previous_hash )`,
 * where `entry` is the record *without* its own `hash`/`previous_hash` fields and
 * `previous_hash` is the hash of the preceding entry in the same stream. Chaining
 * the previous hash in makes the log tamper-*evident*: altering or removing any
 * historical entry breaks every hash from that point forward, which
 * `audit:verify` detects.
 *
 * This is the spine's default integrity primitive — deliberately a linear chain
 * rather than a Merkle tree. The Merkle/transparency-log machinery solves public,
 * adversarial, internet-wide verifiability that an in-database table does not
 * have, and it sequences asynchronously, which conflicts with transactional
 * atomicity. Merkle proofs are escalation tier E2, not the default.
 *
 * The hash recipe is BC-critical: the canonicalisation, the concatenation order
 * (`canonical || previous_hash`) and the genesis value are all fixed and locked
 * by golden-vector tests. Changing any of them invalidates every stored chain.
 */
final class HashCalculator
{
    /**
     * Seed for the first entry of a stream — 64 hex zeros, i.e. the "empty"
     * SHA-256 slot. The genesis entry chains onto this value.
     */
    public const string GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * Shape of a valid chain hash: 64 lowercase hex characters (SHA-256).
     */
    private const string HASH_PATTERN = '/^[0-9a-f]{64}$/';

    public function __construct(
        private readonly CanonicalJsonEncoder $encoder,
    ) {
    }

    /**
     * The hash a stream's first entry chains onto.
     */
    public function genesisHash(): string
    {
        return self::GENESIS_HASH;
    }

    /**
     * Compute the chain hash for an entry.
     *
     * @param array<string, mixed> $entry        the audit record *without* its
     *                                           own `hash` field
     * @param string               $previousHash hash of the preceding entry, or
     *                                           {@see GENESIS_HASH} for the first
     *
     * @return string 64 lowercase hex characters
     *
     * @throws \InvalidArgumentException                  if $previousHash is not a valid chain hash
     * @throws Exception\NonCanonicalizableValueException if $entry cannot be canonicalised
     */
    public function hash(array $entry, string $previousHash): string
    {
        if (1 !== preg_match(self::HASH_PATTERN, $previousHash)) {
            throw new \InvalidArgumentException(\sprintf('Previous hash must be 64 lowercase hex characters, got "%s".', $previousHash));
        }

        $canonical = $this->encoder->encode($entry);

        return hash('sha256', $canonical.$previousHash);
    }

    /**
     * Whether $hash links to $previousHash for the given entry, i.e. the stored
     * hash actually matches a fresh recomputation. Used by chain verification.
     *
     * @param array<string, mixed> $entry
     */
    public function verify(array $entry, string $previousHash, string $hash): bool
    {
        if (1 !== preg_match(self::HASH_PATTERN, $hash)) {
            return false;
        }

        return hash_equals($this->hash($entry, $previousHash), $hash);
    }
}
