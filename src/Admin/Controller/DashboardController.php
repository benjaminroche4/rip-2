<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Admin\Repository\AdminUserRepository;
use App\Admin\Repository\UserActivityRepository;
use App\Admin\Service\ContactPdfRenderer;
use App\Auth\Entity\User;
use App\Auth\Service\AvatarDownloader;
use App\Contact\Repository\ContactRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
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
        private readonly \App\Contact\Service\VisioInvitationMailer $visioMailer,
        private readonly \App\Contact\Repository\ContactEventRepository $contactEvents,
    ) {
    }

    /**
     * No dashboard page for now (it will come back later): the admin root
     * forwards to the first section the user can access, in sidebar order.
     * The route stays: it is the post-login landing and the logo link.
     */
    #[Route('', name: 'dashboard', methods: ['GET'])]
    public function index(string $adminPrefix): Response
    {
        $this->ensureValidPrefix($adminPrefix);

        $sections = [
            'ROLE_SECTION_CONTACTS' => 'admin_contacts',
            'ROLE_SECTION_DOSSIERS' => 'admin_dossiers',
            'ROLE_SECTION_VISITS' => 'admin_visits',
            'ROLE_SECTION_AGENTS' => 'admin_agents',
            'ROLE_SECTION_TOOLS' => 'admin_tools',
            'ROLE_ADMIN' => 'admin_users',
        ];
        foreach ($sections as $role => $route) {
            if ($this->isGranted($role)) {
                return $this->redirectToRoute($route, ['adminPrefix' => $adminPrefix]);
            }
        }

        // Staff with no section granted: nothing to show yet.
        throw $this->createAccessDeniedException();
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
    public function showContact(string $adminPrefix, string $reference, Request $request, \App\Contact\Repository\ContactNoteRepository $noteRepository, \App\Dossier\Repository\DossierRepository $dossierRepository): Response
    {
        $this->ensureValidPrefix($adminPrefix);

        $contact = $this->contactRepository->findOneItemByReference($reference)
            ?? throw $this->createNotFoundException();

        // Navigation context carried from the list ("from" filter), so the
        // arrows keep triaging the category the admin was working in even
        // after this lead's status changed.
        $from = $request->query->getString('from');
        $from = 'all' === $from || null !== \App\Contact\Domain\ContactStatus::tryFrom($from) ? $from : null;

        // A dossier already covers this contact when one was converted from it
        // (source reference) OR when one already carries the same primary-tenant
        // email — the exact idempotency the converter uses. Resolving both here
        // keeps the "Transformer en dossier" action hidden whenever converting
        // would only land on an existing dossier.
        // Exception : un dossier de même email mais sans contact d'origine
        // (créé à la main, ou d'avant la copie de la recherche) reste
        // convertible, la conversion l'adopte et complète son instantané.
        $contactEmail = trim((string) $contact->email);
        $convertedDossier = $dossierRepository->findOneBy(['sourceContactReference' => $contact->reference]);
        if (null === $convertedDossier && '' !== $contactEmail) {
            $sameEmail = $dossierRepository->findByPrimaryTenantEmail($contactEmail);
            $convertedDossier = null !== $sameEmail?->getSourceContactReference() ? $sameEmail : null;
        }

        return $this->render('admin/contacts/show.html.twig', [
            'adminPrefix' => $adminPrefix,
            'contact' => $contact,
            'from' => $from,
            'adjacent' => $this->contactRepository->adjacentReferences($contact->id, $from),
            'previousRequests' => $this->contactRepository->listOtherByEmail($contact->email, $contact->id),
            'notesCount' => \count($noteRepository->listForContact($contact->id)),
            'convertedDossierRef' => $convertedDossier?->getReference(),
            'linkedDossiers' => $dossierRepository->findByPersonEmail($contactEmail, $contact->reference),
        ]);
    }

    #[Route(
        path: [
            'fr' => '/contacts/{reference}/supprimer',
            'en' => '/contacts/{reference}/delete',
        ],
        name: 'contact_delete',
        methods: ['POST'],
        requirements: ['reference' => 'CT-\\d{6}'],
    )]
    public function deleteContact(
        string $adminPrefix,
        string $reference,
        Request $request,
        \App\Contact\Service\RecallCalendarSync $recallSync,
        #[Autowire(service: 'monolog.logger.security')]
        LoggerInterface $securityLogger,
    ): Response {
        $this->ensureValidPrefix($adminPrefix);
        // La suppression définitive d'un lead est réservée aux admins.
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('delete_contact', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $contact = $this->contactRepository->findOneBy(['reference' => $reference])
            ?? throw $this->createNotFoundException();

        // Une visio ou un rappel planifié sort des agendas, sans email : la
        // suppression d'un lead peut être un effacement RGPD, on ne le
        // recontacte pas.
        $this->visioMailer->cancel($contact, notify: false);
        $recallSync->clear($contact);

        // Audit trail: a permanent lead deletion may be a GDPR erasure,
        // keep a trace of who removed which reference.
        $securityLogger->notice('Contact deleted', [
            'actor' => $this->getUser()?->getUserIdentifier(),
            'contact' => $reference,
        ]);

        // Notes et événements suivent via le ON DELETE CASCADE en base.
        $em = $this->contactRepository->createQueryBuilder('c')->getEntityManager();
        $em->remove($contact);
        $em->flush();

        return $this->redirectToRoute('admin_contacts', ['adminPrefix' => $adminPrefix], 303);
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

        // A full-PII export leaves a trace in the follow-up thread.
        $entity = $this->contactRepository->findOneBy(['reference' => $reference]);
        $user = $this->getUser();
        if (null !== $entity) {
            $this->contactEvents->recordKind(
                $entity,
                'pdf_export',
                null,
                $user instanceof User
                    ? (trim(($user->getFirstName() ?? '').' '.($user->getLastName() ?? '')) ?: (string) $user->getEmail())
                    : null,
                $user instanceof User ? $user->getAvatarFilename() : null,
            );
        }

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
    public function showUser(string $adminPrefix, string $uniqueId, string $slug, UserActivityRepository $activityRepository): Response
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
            'assignedLeads' => $activityRepository->assignedLeads($profile->id),
            'managedDossiers' => $activityRepository->managedDossiers($profile->id),
        ]);
    }

    /**
     * Replaces a user's avatar from an admin upload. Plain multipart POST
     * (LiveComponents don't carry files): validate, normalize to WebP via
     * AvatarDownloader, swap the stored file, redirect back to the profile.
     */
    #[Route(
        path: [
            'fr' => '/utilisateurs/{uniqueId}/avatar',
            'en' => '/users/{uniqueId}/avatar',
        ],
        name: 'user_avatar',
        methods: ['POST'],
        requirements: ['uniqueId' => '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}'],
    )]
    public function uploadUserAvatar(
        string $adminPrefix,
        string $uniqueId,
        Request $request,
        EntityManagerInterface $em,
        AvatarDownloader $avatarDownloader,
        #[Autowire(service: 'monolog.logger.security')]
        LoggerInterface $securityLogger,
    ): Response {
        $this->ensureValidPrefix($adminPrefix);

        if (!$this->isCsrfTokenValid('user-avatar', (string) $request->request->get('_csrf_token'))) {
            throw new BadRequestHttpException('Invalid CSRF token.');
        }

        $profile = $this->adminUserRepository->findByUniqueId($uniqueId)
            ?? throw $this->createNotFoundException();
        $user = $em->find(User::class, $profile->id)
            ?? throw $this->createNotFoundException();

        $redirect = $this->redirectToRoute('admin_user_show', [
            'adminPrefix' => $adminPrefix,
            'uniqueId' => $uniqueId,
            'slug' => $profile->slug,
        ]);

        $file = $request->files->get('avatar');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return $redirect;
        }

        // Mime sniffed from the content, never trusted from the client.
        if (!\in_array((string) $file->getMimeType(), ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            return $redirect;
        }

        $bytes = @file_get_contents($file->getPathname());
        if (false === $bytes) {
            return $redirect;
        }

        // storeFromBytes re-encodes to WebP 256×256 and enforces the size cap.
        $filename = $avatarDownloader->storeFromBytes($bytes, (string) $user->getUniqueId());
        if (null === $filename) {
            return $redirect;
        }

        if (null !== $user->getAvatarFilename()) {
            $avatarDownloader->delete($user->getAvatarFilename());
        }
        $user->setAvatarFilename($filename);
        $em->flush();

        // Audit trail: replacing a profile picture is identity-sensitive.
        $securityLogger->notice('User administration: avatar replaced', [
            'actor' => $this->getUser()?->getUserIdentifier(),
            'target' => (string) $user->getEmail(),
        ]);

        return $redirect;
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
