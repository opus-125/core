<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Command;

use Opus125\GdprBundle\Ropa\RecordsOfProcessingGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Records of processing (Art. 30): generate a processing register from the
 * registry. JSON by default, or a readable table.
 */
#[AsCommand(name: 'gdpr:ropa:export', description: 'Export the records of processing from the registry (Art. 30)')]
final class RopaExportCommand extends Command
{
    public function __construct(
        private readonly RecordsOfProcessingGenerator $generator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Output format: json or table', 'json');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $ropa = $this->generator->generate();

        if ('table' === $input->getOption('format')) {
            $io->note($ropa['scope_note']);
            foreach ($ropa['activities'] as $activity) {
                $io->section(\sprintf('Purpose: %s', $activity['purpose'] ?? '(unspecified)'));
                $io->writeln(\sprintf('  Categories:  %s', implode(', ', $activity['categories'])));
                $io->writeln(\sprintf('  Legal bases: %s', '' === implode(', ', $activity['legal_bases']) ? '(none declared)' : implode(', ', $activity['legal_bases'])));
                $io->writeln(\sprintf('  Special category (Art. 9): %s', $activity['has_sensitive'] ? 'yes' : 'no'));
                $rows = array_map(
                    static fn (array $f): array => [$f['entity'], $f['property'], $f['category'], $f['basis'] ?? '', $f['sensitive'] ? 'yes' : ''],
                    $activity['fields'],
                );
                $io->table(['Entity', 'Field', 'Category', 'Basis', 'Sensitive'], $rows);
            }

            return Command::SUCCESS;
        }

        $output->writeln((string) json_encode($ropa, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));

        return Command::SUCCESS;
    }
}
