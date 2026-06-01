<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Antrag;
use App\Entity\Buerger;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Opus\AuditBundle\Actor\Actor;
use Opus\AuditBundle\Actor\AuditContext;
use Opus\AuditBundle\Crypto\KeyStoreInterface;
use Opus\AuditBundle\Integrity\ChainVerifier;
use Opus\AuditBundle\Model\AuditEntry;
use Opus\AuditBundle\Model\AuditEvent;
use Opus\AuditBundle\Model\AuditSeal;
use Opus\AuditBundle\Model\CryptoKey;
use Opus\AuditBundle\Reading\AuditEntryReader;
use Opus\AuditBundle\Subject\SubjectResolverInterface;
use Opus\AuditBundle\Transaction\AuditTransaction;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Guided walkthrough of the Spine: record changes, verify the chain, then
 * crypto-shred a subject and watch the content disappear while the chain stays
 * valid.
 */
#[AsCommand(name: 'app:demo', description: 'Demonstrate the opus/audit-bundle Spine end to end')]
final class DemoCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditTransaction $transaction,
        private readonly AuditContext $auditContext,
        private readonly AuditEntryReader $reader,
        private readonly ChainVerifier $verifier,
        private readonly KeyStoreInterface $keyStore,
        private readonly SubjectResolverInterface $subjectResolver,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('opus/audit-bundle — Spine demo');

        $this->resetSchema();
        $io->writeln('<comment>Schema (re)created.</comment>');

        // 1. Record changes as a known clerk.
        $io->section('1) Recording changes (as clerk "Anna Berger")');
        $antrag = $this->auditContext->runAs(
            Actor::user('clerk-anna', 'Anna Berger'),
            fn (): Antrag => $this->recordChanges(),
        );
        $io->writeln('Created an Antrag, changed its status and edited a sensitive note — 3 audited changes.');

        // 2. Show the (decrypted) trail.
        $io->section('2) The audit trail');
        $this->renderTrail($io);

        // 3. Verify integrity.
        $io->section('3) Tamper-evidence');
        $result = $this->verifier->verifyFull('antrag');
        $io->writeln(\sprintf('Chain verification: <info>%s</info> (%d entries).', $result->valid ? 'INTACT' : 'BROKEN', $result->checkedCount));

        // 4. Crypto-shred the applicant (GDPR Art. 17).
        $io->section('4) Crypto-shredding the applicant (GDPR Art. 17)');
        foreach ($this->subjectResolver->resolveForEntity($antrag) as $subject) {
            $this->keyStore->shred($subject);
            $io->writeln(\sprintf('Shredded subject key: <comment>%s</comment>', $subject));
        }

        $io->writeln('');
        $io->writeln('The trail again — note the now-erased note, while metadata survives:');
        $this->renderTrail($io);

        $after = $this->verifier->verifyFull('antrag');
        $io->writeln(\sprintf('Chain verification after shredding: <info>%s</info> — content erased, proof intact.', $after->valid ? 'INTACT' : 'BROKEN'));

        $io->section('Next');
        $io->listing([
            'php bin/console audit:verify',
            'php bin/console audit:export --stream=antrag --format=json',
            'php bin/console audit:seal',
            'php bin/console audit:purge --force',
        ]);
        $io->success('Done.');

        return Command::SUCCESS;
    }

    private function recordChanges(): Antrag
    {
        $buerger = new Buerger('Maria Huber');
        $antrag = new Antrag($buerger, 'Baugenehmigung Carport');
        $antrag->setNotiz('Vertraulicher Vermerk: Rückfrage offen.');

        $this->transaction->run(function () use ($buerger, $antrag): void {
            $this->em->persist($buerger);
            $this->em->persist($antrag);
            $this->em->flush();
        });

        $this->transaction->run(function () use ($antrag): void {
            $antrag->setStatus('in Bearbeitung');
            $this->em->flush();
        });

        $this->transaction->run(function () use ($antrag): void {
            $antrag->setNotiz('Vertraulicher Vermerk: genehmigt unter Auflagen.');
            $this->em->flush();
        });

        return $antrag;
    }

    private function renderTrail(SymfonyStyle $io): void
    {
        $this->em->clear();
        /** @var list<AuditEntry> $entries */
        $entries = $this->em->getRepository(AuditEntry::class)->findBy(['streamId' => 'antrag'], ['sequenceNo' => 'ASC']);

        $rows = [];
        foreach ($entries as $entry) {
            $view = $this->reader->toArray($entry);
            $changed = [];
            foreach ($view['changes'] as $field => $diff) {
                $changed[] = $field.'='.$this->describe($diff);
            }

            $rows[] = [
                (string) $view['sequence_no'],
                $view['action'],
                $view['actor_label'] ?? $view['actor_type'],
                implode(', ', $changed),
            ];
        }

        $io->table(['#', 'action', 'actor', 'changes'], $rows);
    }

    private function describe(mixed $diff): string
    {
        if (\is_array($diff) && \array_key_exists('new', $diff)) {
            return \sprintf('"%s"', \is_scalar($diff['new']) ? (string) $diff['new'] : json_encode($diff['new']));
        }

        return (string) json_encode($diff);
    }

    private function resetSchema(): void
    {
        $tool = new SchemaTool($this->em);
        $classes = array_map(
            $this->em->getClassMetadata(...),
            [Antrag::class, Buerger::class, AuditEntry::class, AuditEvent::class, AuditSeal::class, CryptoKey::class],
        );
        $tool->dropSchema($classes);
        $tool->createSchema($classes);
    }
}
