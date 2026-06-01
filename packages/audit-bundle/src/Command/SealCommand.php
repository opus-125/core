<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Command;

use Opus\AuditBundle\Sealing\Sealer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Writes seals (head checkpoints) for the audit streams.
 *
 * Run periodically (e.g. daily) and mirror the resulting seals to WORM/external
 * storage: a trusted seal anchors verification and bounds the segment that must
 * be re-walked.
 */
#[AsCommand(name: 'audit:seal', description: 'Seal the current head of the audit streams')]
final class SealCommand extends Command
{
    public function __construct(
        private readonly Sealer $sealer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('stream', null, InputOption::VALUE_REQUIRED, 'Seal only this stream (default: all)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $stream = $input->getOption('stream');

        if (\is_string($stream)) {
            $sealId = $this->sealer->seal($stream);
            if (null === $sealId) {
                $io->warning(\sprintf('Stream "%s" has no entries to seal.', $stream));

                return Command::SUCCESS;
            }
            $io->success(\sprintf('Sealed stream "%s" (seal %s).', $stream, $sealId));

            return Command::SUCCESS;
        }

        $count = $this->sealer->sealAll();
        $io->success(\sprintf('Sealed %d stream(s).', $count));

        return Command::SUCCESS;
    }
}
