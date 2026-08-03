<?php

declare(strict_types=1);

namespace App\Dossier\Controller;

use App\Contact\Entity\Contact;
use App\Dossier\Repository\DossierRepository;
use App\Dossier\Service\ContactDossierConverter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

// Same security model as other admin controllers: access_control on the
// prefix in security.yaml + hash_equals here. A wrong-but-format-valid
// prefix returns 404 before triggering any auth challenge.
#[Route(
    path: [
        'fr' => '/{_locale}/{adminPrefix}/admin',
        'en' => '/{_locale}/{adminPrefix}/admin',
    ],
    name: 'admin_',
    requirements: [
        '_locale' => 'fr|en',
        'adminPrefix' => '[a-zA-Z0-9_-]{16,64}',
    ],
)]
final class DossierController extends AbstractController
{
    public function __construct(
        #[Autowire('%admin_path_prefix%')]
        private readonly string $adminPathPrefix,
    ) {
    }

    #[Route(
        path: [
            'fr' => '/dossiers',
            'en' => '/files',
        ],
        name: 'dossiers',
        methods: ['GET'],
    )]
    public function index(string $adminPrefix, DossierRepository $repository): Response
    {
        $this->ensureValidPrefix($adminPrefix);

        return $this->render('admin/dossiers/index.html.twig', [
            'adminPrefix' => $adminPrefix,
            'dossiers' => $repository->findSummaries(),
        ]);
    }

    #[Route(
        path: [
            'fr' => '/dossiers/{reference}',
            'en' => '/files/{reference}',
        ],
        name: 'dossier_show',
        requirements: ['reference' => 'DS-\d{6}'],
        methods: ['GET'],
    )]
    public function show(string $adminPrefix, string $reference, DossierRepository $repository): Response
    {
        $this->ensureValidPrefix($adminPrefix);

        $dossier = $repository->findDetailsByReference($reference);
        if (null === $dossier) {
            throw $this->createNotFoundException();
        }

        return $this->render('admin/dossiers/show.html.twig', [
            'adminPrefix' => $adminPrefix,
            'dossier' => $dossier,
        ]);
    }

    /**
     * "Transformer en dossier" on the contact detail page: the contact
     * becomes the dossier's primary tenant. Idempotent (same contact email →
     * same dossier), always lands on the dossier detail page.
     */
    #[Route(
        path: [
            'fr' => '/dossiers/depuis-contact/{reference}',
            'en' => '/files/from-contact/{reference}',
        ],
        name: 'dossier_from_contact',
        methods: ['POST'],
    )]
    public function createFromContact(
        string $adminPrefix,
        string $reference,
        Request $request,
        EntityManagerInterface $em,
        ContactDossierConverter $converter,
    ): Response {
        $this->ensureValidPrefix($adminPrefix);

        if (!$this->isCsrfTokenValid('dossier_from_contact', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $contact = $em->getRepository(Contact::class)->findOneBy(['reference' => $reference])
            ?? throw $this->createNotFoundException();

        $dossier = $converter->convert($contact);

        return $this->redirectToRoute('admin_dossier_show', [
            'adminPrefix' => $adminPrefix,
            'reference' => $dossier->getReference(),
        ], Response::HTTP_SEE_OTHER);
    }

    private function ensureValidPrefix(string $adminPrefix): void
    {
        if (!hash_equals($this->adminPathPrefix, $adminPrefix)) {
            throw $this->createNotFoundException();
        }
    }
}
