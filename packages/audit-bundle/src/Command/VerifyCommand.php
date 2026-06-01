<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Command;

use Opus\AuditBundle\Integrity\ChainVerifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Verifies audit chains and reports tampering. Suitable for CI/monitoring: the
 * exit code is non-zero when any chain is broken.
 *
 * By default it verifies each stream incrementally — only the segment since the
 * last seal — which is O(segment). Use `--full` for a complete re-walk.
 */
#[AsCommand(name: 'audit:verify', description: 'Verify the integrity of the audit hash-chains')]
final class VerifyCommand extends Command
{
    public function __construct(
        private readonly ChainVerifier $verifier,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('stream', null, InputOption::VALUE_REQUIRED, 'Verify only this stream (default: all)')
            ->addOption('full', null, InputOption::VALUE_NONE, 'Re-walk the whole chain instead of since the last seal');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $full = (bool) $input->getOption('full');

        $stream = $input->getOption('stream');
        $streams = \is_string($stream) ? [$stream] : $this->verifier->streams();

        if ([] === $streams) {
            $io->success('No audit streams to verify.');

            return Command::SUCCESS;
        }

        $allValid = true;
        $rows = [];

        foreach ($streams as $streamId) {
            $result = $full ? $this->verifier->verifyFull($streamId) : $this->verifier->verifyIncremental($streamId);
            $allValid = $allValid && $result->valid;

            $rows[] = [
                $streamId,
                $result->valid ? '<info>OK</info>' : '<error>BROKEN</error>',
                (string) $result->checkedCount,
                $result->valid ? '' : (string) $result->brokenAtSequence,
                $result->valid ? '' : $result->message,
            ];
        }

        $io->table(['Stream', 'Status', 'Checked', 'Broken @', 'Detail'], $rows);

        if (!$allValid) {
            $io->error('One or more audit chains failed verification.');

            return Command::FAILURE;
        }

        $io->success(\sprintf('All %d audit stream(s) verified intact (%s).', \count($streams), $full ? 'full walk' : 'since last seal'));

        return Command::SUCCESS;
    }
}
