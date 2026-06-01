<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Model;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Opus\AuditBundle\Repository\AuditSealRepository;

/**
 * A periodically written, externally-mirrorable checkpoint of a stream's head
 * (Spine).
 *
 * A seal pins `(stream_id, last_sequence, head_hash)` at a point in time. Once
 * mirrored to WORM/external storage it anchors the chain: verification only has
 * to re-walk the segment *between two seals* (O(segment)) instead of the whole
 * stream, and a tampered head can be caught by comparing against the last
 * trusted seal.
 *
 * After a retention purge drops a block at the chain's start, a new seal is
 * written with {@see $genesis} = true to mark the new, legitimate beginning of
 * the (now shorter) verifiable chain.
 */
#[ORM\Entity(repositoryClass: AuditSealRepository::class, readOnly: true)]
#[ORM\Table(name: Schema::SEAL_TABLE)]
#[ORM\Index(name: 'idx_audit_seal_stream', columns: ['stream_id', 'sealed_at'])]
class AuditSeal
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'stream_id', type: Types::STRING, length: 255)]
    private string $streamId;

    #[ORM\Column(name: 'last_sequence', type: Types::BIGINT)]
    private int $lastSequence;

    #[ORM\Column(name: 'head_hash', type: Types::STRING, length: 64)]
    private string $headHash;

    #[ORM\Column(name: 'sealed_at', type: Types::STRING, length: 32)]
    private string $sealedAt;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $genesis;

    public function __construct(
        string $id,
        string $streamId,
        int $lastSequence,
        string $headHash,
        string $sealedAt,
        bool $genesis = false,
    ) {
        $this->id = $id;
        $this->streamId = $streamId;
        $this->lastSequence = $lastSequence;
        $this->headHash = $headHash;
        $this->sealedAt = $sealedAt;
        $this->genesis = $genesis;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getStreamId(): string
    {
        return $this->streamId;
    }

    public function getLastSequence(): int
    {
        return $this->lastSequence;
    }

    public function getHeadHash(): string
    {
        return $this->headHash;
    }

    public function getSealedAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->sealedAt);
    }

    public function isGenesis(): bool
    {
        return $this->genesis;
    }
}
