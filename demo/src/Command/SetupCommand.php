<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Application;
use App\Entity\Citizen;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Opus\AuditBundle\Model\AuditEntry;
use Opus\AuditBundle\Model\ShreddedSubject;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Creates the schema and seeds a couple of applications, so the web demo at `/`
 * has something to show.
 */
#[AsCommand(name: 'app:setup', description: 'Reset the demo schema and seed sample data')]
final class SetupCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $tool = new SchemaTool($this->em);
        $classes = array_map($this->em->getClassMetadata(...), [
            Application::class, Citizen::class, AuditEntry::class, ShreddedSubject::class,
        ]);
        $tool->dropSchema($classes);
        $tool->createSchema($classes);

        $maria = new Citizen('Maria Huber');
        $josef = new Citizen('Josef Bauer');
        $this->em->persist($maria);
        $this->em->persist($josef);

        $carport = new Application($maria, 'Building permit: carport');
        $carport->setNote('Confidential note: query pending.');
        $parking = new Application($josef, 'Parking permit: Hauptstraße');
        $this->em->persist($carport);
        $this->em->persist($parking);

        $this->em->flush();

        $io->success('Demo schema created and seeded. Start the server and open / to try it.');

        return Command::SUCCESS;
    }
}
