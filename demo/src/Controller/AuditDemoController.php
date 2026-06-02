<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Application;
use App\Entity\Citizen;
use Doctrine\ORM\EntityManagerInterface;
use Opus\AuditBundle\Actor\Actor;
use Opus\AuditBundle\Actor\AuditContext;
use Opus\AuditBundle\Model\AuditEntry;
use Opus\AuditBundle\Repository\AuditEntryRepository;
use Opus\AuditBundle\Serializer\AuditEntryNormalizer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A small web UI that shows the audit bundle at work: edit a field on an
 * application, see the audit trail update, and crypto-shred a citizen.
 */
final class AuditDemoController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditEntryNormalizer $normalizer,
        private readonly AuditContext $auditContext,
    ) {
    }

    #[Route('/', name: 'app_index', methods: ['GET'])]
    public function index(): Response
    {
        /** @var AuditEntryRepository $repository */
        $repository = $this->em->getRepository(AuditEntry::class);

        $applications = [];
        foreach ($this->em->getRepository(Application::class)->findBy([], ['subject' => 'ASC']) as $application) {
            $trail = array_map(
                $this->normalizer->normalize(...),
                $repository->findForTarget(Application::class, $application->getId()),
            );
            $applications[] = ['entity' => $application, 'trail' => $trail];
        }

        return $this->render('demo/index.html.twig', ['applications' => $applications]);
    }

    #[Route('/applications/{id}/field', name: 'app_update', methods: ['POST'])]
    public function update(string $id, Request $request): Response
    {
        $application = $this->em->getRepository(Application::class)->find($id);
        if (!$application instanceof Application) {
            throw $this->createNotFoundException();
        }

        $status = $request->request->getString('status');
        $note = $request->request->getString('note');

        // Attribute the change to a named clerk for the demo.
        $this->auditContext->runAs(Actor::user('clerk-anna', 'Anna Berger'), function () use ($application, $status, $note): void {
            if ('' !== $status) {
                $application->setStatus($status);
            }
            if ('' !== $note) {
                $application->setNote($note);
            }
            $this->em->flush();
        });

        return $this->redirectToRoute('app_index');
    }

    #[Route('/citizens/{id}/shred', name: 'app_shred', methods: ['POST'])]
    public function shred(string $id): Response
    {
        // Anonymise the citizen (GDPR Art. 17): dropping their key crypto-shreds
        // their sensitive audit values. Just a normal domain operation.
        $citizen = $this->em->getRepository(Citizen::class)->find($id);
        if ($citizen instanceof Citizen) {
            $citizen->anonymize();
            $this->em->flush();
        }

        return $this->redirectToRoute('app_index');
    }
}
