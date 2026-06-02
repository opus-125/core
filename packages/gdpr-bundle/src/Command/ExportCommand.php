<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Command;

use Opus125\DataContracts\Subject\SubjectReference;
use Opus125\GdprBundle\Access\Export\CsvExporter;
use Opus125\GdprBundle\Access\Export\JsonExporter;
use Opus125\GdprBundle\Access\SubjectAccessService;
use Opus125\GdprBundle\Exception\SubjectNotFoundException;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Subject access / portability export (Art. 15 / 20): collect a subject's data
 * and write it as JSON or CSV to stdout or a file.
 */
#[AsCommand(name: 'gdpr:export', description: 'Export all personal data of a subject (Art. 15 / 20)')]
final class ExportCommand extends Command
{
    public function __construct(
        private readonly SubjectAccessService $access,
        private readonly JsonExporter $jsonExporter,
        private readonly CsvExporter $csvExporter,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('subject-class', InputArgument::REQUIRED, 'The data-subject entity class (FQCN)')
            ->addArgument('subject-id', InputArgument::REQUIRED, 'The data-subject identifier')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Export format: json or csv', JsonExporter::FORMAT)
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Write to this file instead of stdout');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var class-string $class */
        $class = (string) $input->getArgument('subject-class');
        $subject = new SubjectReference($class, (string) $input->getArgument('subject-id'));
        $format = (string) $input->getOption('format');

        try {
            $report = $this->access->collect($subject);
        } catch (SubjectNotFoundException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $rendered = match ($format) {
            JsonExporter::FORMAT => $this->jsonExporter->export($report, $this->clock->now()),
            CsvExporter::FORMAT => $this->csvExporter->export($report, $this->clock->now()),
            default => null,
        };

        if (null === $rendered) {
            $io->error(\sprintf('Unknown format "%s"; use "json" or "csv".', $format));

            return Command::INVALID;
        }

        $target = $input->getOption('output');
        if (\is_string($target) && '' !== $target) {
            file_put_contents($target, $rendered);
            $io->success(\sprintf('Wrote %s export for %s to %s.', $format, $subject, $target));

            return Command::SUCCESS;
        }

        $output->write($rendered);

        return Command::SUCCESS;
    }
}
