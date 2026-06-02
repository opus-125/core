<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Command;

use Opus125\DataContracts\Subject\SubjectReference;
use Opus125\GdprBundle\Erasure\ErasureOutcome;
use Opus125\GdprBundle\Erasure\ErasureService;
use Opus125\GdprBundle\Exception\SubjectNotFoundException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Erase a subject's data (Art. 17) per the declared per-field strategies. Refuses
 * unless `--force` is given, since the operation is irreversible.
 */
#[AsCommand(name: 'gdpr:erase', description: 'Erase / anonymise a subject\'s personal data (Art. 17)')]
final class EraseCommand extends Command
{
    public function __construct(
        private readonly ErasureService $erasure,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('subject-class', InputArgument::REQUIRED, 'The data-subject entity class (FQCN)')
            ->addArgument('subject-id', InputArgument::REQUIRED, 'The data-subject identifier')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Actually erase (the operation is irreversible)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var class-string $class */
        $class = (string) $input->getArgument('subject-class');
        $subject = new SubjectReference($class, (string) $input->getArgument('subject-id'));

        if (!$input->getOption('force')) {
            $io->warning(\sprintf('Erasure of %s is irreversible. Re-run with --force to proceed.', $subject));

            return Command::FAILURE;
        }

        try {
            $report = $this->erasure->erase($subject);
        } catch (SubjectNotFoundException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($report->blocked) {
            $io->error(\sprintf('Erasure blocked by legal hold%s.', null !== $report->blockReason ? ': '.$report->blockReason : ''));

            return Command::FAILURE;
        }

        $io->success(\sprintf('Erased personal data for %s.', $subject));
        $io->listing([
            \sprintf('nullified:      %d', \count($report->withOutcome(ErasureOutcome::Nullified))),
            \sprintf('pseudonymized:  %d', \count($report->withOutcome(ErasureOutcome::Pseudonymized))),
            \sprintf('crypto-shred:   %s', $report->keyShredded ? 'yes (subject key destroyed)' : 'no'),
            \sprintf('skipped shared: %d', \count($report->withOutcome(ErasureOutcome::SkippedShared))),
        ]);

        return Command::SUCCESS;
    }
}
