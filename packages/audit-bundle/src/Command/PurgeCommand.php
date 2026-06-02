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
 * Deletes audit entries past their retention. Destructive, so it refuses to run
 * without `--force`.
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
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Actually delete (without it, the command is a no-op)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$input->getOption('force')) {
            $io->warning('Refusing to purge without --force. Purge permanently deletes audit entries past their retention.');

            return Command::SUCCESS;
        }

        $removed = $this->purger->purge();
        $io->success(\sprintf('Purged %d expired audit entr%s.', $removed, 1 === $removed ? 'y' : 'ies'));

        return Command::SUCCESS;
    }
}
