<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Application;
use App\Entity\Citizen;
use Doctrine\ORM\EntityManagerInterface;
use Opus125\AuditBundle\Actor\Actor;
use Opus125\AuditBundle\Actor\AuditContext;
use Opus125\AuditBundle\Model\AuditEntry;
use Opus125\AuditBundle\Repository\AuditEntryRepository;
use Opus125\AuditBundle\Serializer\AuditEntryNormalizer;
use Opus125\DataContracts\Subject\SubjectReference;
use Opus125\GdprBundle\Erasure\ErasureService;
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
        private readonly ErasureService $erasure,
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
        // Erase the citizen (GDPR Art. 17) through the GDPR bundle: their name is
        // pseudonymised and their data key crypto-shredded — which, via the key
        // bridge, also redacts the applicant's sensitive audit values, while the
        // audit hash-chain stays valid.
        if (null !== $this->em->getRepository(Citizen::class)->find($id)) {
            $this->erasure->erase(new SubjectReference(Citizen::class, $id));
        }

        return $this->redirectToRoute('app_index');
    }
}
