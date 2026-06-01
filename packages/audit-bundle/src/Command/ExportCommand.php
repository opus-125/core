<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Opus\AuditBundle\Export\AuditExporter;
use Opus\AuditBundle\Model\AuditEntry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Exports audit entries as JSON (complete, decrypted) or CSV (flat metadata).
 *
 * Filterable by stream or target entity; the JSON export doubles as the GDPR
 * access/portability deliverable. Crypto-shredded values appear as the
 * redaction placeholder, never as cleartext.
 */
#[AsCommand(name: 'audit:export', description: 'Export audit entries as JSON or CSV')]
final class ExportCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditExporter $exporter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'json or csv', 'json')
            ->addOption('stream', null, InputOption::VALUE_REQUIRED, 'Filter by stream')
            ->addOption('entity-class', null, InputOption::VALUE_REQUIRED, 'Filter by target entity class')
            ->addOption('entity-id', null, InputOption::VALUE_REQUIRED, 'Filter by target entity id (requires --entity-class)')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Write to a file instead of stdout');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $format = strtolower((string) $input->getOption('format'));
        if (!\in_array($format, ['json', 'csv'], true)) {
            $io->error('Format must be "json" or "csv".');

            return Command::INVALID;
        }

        $entries = $this->queryEntries($input);

        $payload = 'csv' === $format ? $this->exporter->toCsv($entries) : $this->exporter->toJson($entries);

        $target = $input->getOption('output');
        if (\is_string($target)) {
            if (false === file_put_contents($target, $payload)) {
                $io->error(\sprintf('Could not write to "%s".', $target));

                return Command::FAILURE;
            }
            $io->success(\sprintf('Exported %d entr%s to %s.', \count($entries), 1 === \count($entries) ? 'y' : 'ies', $target));

            return Command::SUCCESS;
        }

        $output->writeln($payload);

        return Command::SUCCESS;
    }

    /**
     * @return list<AuditEntry>
     */
    private function queryEntries(InputInterface $input): array
    {
        $qb = $this->entityManager->getRepository(AuditEntry::class)->createQueryBuilder('e')
            ->orderBy('e.streamId', 'ASC')
            ->addOrderBy('e.sequenceNo', 'ASC');

        $stream = $input->getOption('stream');
        if (\is_string($stream)) {
            $qb->andWhere('e.streamId = :stream')->setParameter('stream', $stream);
        }

        $entityClass = $input->getOption('entity-class');
        if (\is_string($entityClass)) {
            $qb->andWhere('e.entityClass = :class')->setParameter('class', $entityClass);

            $entityId = $input->getOption('entity-id');
            if (\is_string($entityId)) {
                $qb->andWhere('e.entityId = :id')->setParameter('id', $entityId);
            }
        }

        /** @var list<AuditEntry> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }
}
