<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Command;

use Opus\AuditBundle\Retention\Purger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Purges audit entries past their statutory retention.
 *
 * Destructive and irreversible, so it refuses to run without `--force`. Entries
 * under a legal hold, within retention, or of a class with no policy are kept;
 * a genesis seal is written so the surviving chain stays verifiable.
 */
#[AsCommand(name: 'audit:purge', description: 'Purge audit entries that have outlived their retention')]
final class PurgeCommand extends Command
{
    public function __construct(
        private readonly Purger $purger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('stream', null, InputOption::VALUE_REQUIRED, 'Purge only this stream (default: all)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Actually delete (without it, the command is a no-op)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$input->getOption('force')) {
            $io->warning('Refusing to purge without --force. Purge permanently deletes audit entries past their retention.');

            return Command::SUCCESS;
        }

        $stream = $input->getOption('stream');
        $reports = \is_string($stream) ? [$this->purger->purge($stream)] : $this->purger->purgeAll();

        $total = 0;
        $rows = [];
        foreach ($reports as $report) {
            $total += $report->purgedCount;
            if ($report->purgedAnything()) {
                $rows[] = [$report->streamId, (string) $report->purgedCount, (string) $report->newGenesisSequence];
            }
        }

        if ([] === $rows) {
            $io->success('Nothing to purge — all entries are within retention or held.');

            return Command::SUCCESS;
        }

        $io->table(['Stream', 'Purged', 'New genesis @'], $rows);
        $io->success(\sprintf('Purged %d entr%s across %d stream(s).', $total, 1 === $total ? 'y' : 'ies', \count($rows)));

        return Command::SUCCESS;
    }
}
