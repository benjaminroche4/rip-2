<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Admin\Repository\AdminUserRepository;
use App\Admin\Service\ContactPdfRenderer;
use App\Contact\Repository\ContactRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

// NOTE: pas de #[IsGranted('ROLE_ADMIN')] ici. La sécurité passe par
// access_control dans security.yaml, ancré sur la vraie valeur de
// %admin_path_prefix%. Avec IsGranted au niveau controller, un mauvais
// prefix de format valide déclencherait un login redirect anonyme — ce qui
// révélerait le pattern de l'admin. Avec access_control + hash_equals dans
// le controller, un mauvais prefix tombe en 404 avant tout challenge auth.
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
final class DashboardController extends AbstractController
{
    public function __construct(
        #[Autowire('%admin_path_prefix%')]
        private readonly string $adminPathPrefix,
        private readonly ContactRepository $contactRepository,
        private readonly AdminUserRepository $adminUserRepository,
    ) {
    }

    #[Route('', name: 'dashboard', methods: ['GET'])]
    public function index(string $adminPrefix): Response
    {
        $this->ensureValidPrefix($adminPrefix);

        return $this->render('admin/dashboard/index.html.twig', [
            'adminPrefix' => $adminPrefix,
        ]);
    }

    #[Route(
        path: [
            'fr' => '/utilisateurs',
            'en' => '/users',
        ],
        name: 'users',
        methods: ['GET'],
    )]
    public function users(string $adminPrefix): Response
    {
        $this->ensureValidPrefix($adminPrefix);

        return $this->render('admin/users/index.html.twig', [
            'adminPrefix' => $adminPrefix,
        ]);
    }

    #[Route(
        path: [
            'fr' => '/contacts',
            'en' => '/contacts',
        ],
        name: 'contacts',
        methods: ['GET'],
    )]
    public function contacts(string $adminPrefix): Response
    {
        $this->ensureValidPrefix($adminPrefix);

        return $this->render('admin/contacts/index.html.twig', [
            'adminPrefix' => $adminPrefix,
        ]);
    }

    #[Route(
        path: [
            'fr' => '/contacts/{reference}',
            'en' => '/contacts/{reference}',
        ],
        name: 'contact_show',
        methods: ['GET'],
        requirements: ['reference' => 'CT-\d{6}'],
    )]
    public function showContact(string $adminPrefix, string $reference, Request $request): Response
    {
        $this->ensureValidPrefix($adminPrefix);

        $contact = $this->contactRepository->findOneItemByReference($reference)
            ?? throw $this->createNotFoundException();

        // Navigation context carried from the list ("from" filter), so the
        // arrows keep triaging the category the admin was working in even
        // after this lead's status changed.
        $from = $request->query->getString('from');
        $from = 'all' === $from || null !== \App\Contact\Domain\ContactStatus::tryFrom($from) ? $from : null;

        return $this->render('admin/contacts/show.html.twig', [
            'adminPrefix' => $adminPrefix,
            'contact' => $contact,
            'from' => $from,
            'adjacent' => $this->contactRepository->adjacentReferences($contact->id, $from),
            'previousRequests' => $this->contactRepository->listOtherByEmail($contact->email, $contact->id),
        ]);
    }

    #[Route(
        path: [
            'fr' => '/contacts/{reference}/pdf',
            'en' => '/contacts/{reference}/pdf',
        ],
        name: 'contact_pdf',
        methods: ['GET'],
        requirements: ['reference' => 'CT-\d{6}'],
    )]
    public function contactPdf(string $adminPrefix, string $reference, ContactPdfRenderer $renderer): Response
    {
        $this->ensureValidPrefix($adminPrefix);

        $contact = $this->contactRepository->findOneItemByReference($reference)
            ?? throw $this->createNotFoundException();

        $response = new Response($renderer->render($contact));
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $renderer->filename($contact),
        ));

        return $response;
    }

    /**
     * Resolves a user by its public ULID. The {slug} segment is purely
     * decorative — if it doesn't match the current display slug (e.g. the
     * user was renamed), we 302 to the canonical URL so old links keep
     * working without poisoning bookmarks with a stale slug.
     */
    #[Route(
        path: [
            'fr' => '/utilisateurs/{uniqueId}/{slug}',
            'en' => '/users/{uniqueId}/{slug}',
        ],
        name: 'user_show',
        methods: ['GET'],
        requirements: [
            'uniqueId' => '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}',
            'slug' => '[a-z0-9-]+',
        ],
    )]
    public function showUser(string $adminPrefix, string $uniqueId, string $slug): Response
    {
        $this->ensureValidPrefix($adminPrefix);

        $profile = $this->adminUserRepository->findByUniqueId($uniqueId)
            ?? throw $this->createNotFoundException();

        if ($slug !== $profile->slug) {
            return $this->redirectToRoute('admin_user_show', [
                'adminPrefix' => $adminPrefix,
                'uniqueId' => $uniqueId,
                'slug' => $profile->slug,
            ]);
        }

        return $this->render('admin/users/show.html.twig', [
            'adminPrefix' => $adminPrefix,
            'profile' => $profile,
        ]);
    }

    /**
     * Compares the URL prefix to the configured secret in constant time.
     * Throws 404 (not 403) on mismatch to avoid leaking the existence
     * of the admin space on a wrong-but-plausible URL.
     */
    private function ensureValidPrefix(string $adminPrefix): void
    {
        if (!hash_equals($this->adminPathPrefix, $adminPrefix)) {
            throw $this->createNotFoundException();
        }
    }
}
