<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Admin\Form\ProfilePasswordType;
use App\Admin\Repository\AdminUserRepository;
use App\Admin\Repository\UserActivityRepository;
use App\Auth\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Monolog\Attribute\WithMonologChannel;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

// Same security model as other admin controllers: access_control on the
// prefix in security.yaml + hash_equals here. A wrong-but-format-valid
// prefix returns 404 before triggering any auth challenge. The profile is
// open to every staff member (ROLE_STAFF shell rule): it only ever shows
// the logged-in user's own data.
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
#[WithMonologChannel('security')]
final class ProfileController extends AbstractController
{
    public function __construct(
        #[Autowire('%admin_path_prefix%')]
        private readonly string $adminPathPrefix,
        private readonly AdminUserRepository $users,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(
        path: [
            'fr' => '/mon-profil',
            'en' => '/my-profile',
        ],
        name: 'profile',
        methods: ['GET'],
    )]
    public function show(string $adminPrefix, UserActivityRepository $activity): Response
    {
        $this->ensureValidPrefix($adminPrefix);
        $user = $this->currentUser();

        return $this->renderProfile($adminPrefix, $user, $activity, $this->createForm(ProfilePasswordType::class));
    }

    /**
     * Own-password change: current password verified by the form constraint,
     * new password held to the same strength bar as the reset flow. Classic
     * POST + redirect (PRG), errors re-render the page in place.
     */
    #[Route(
        path: [
            'fr' => '/mon-profil/mot-de-passe',
            'en' => '/my-profile/password',
        ],
        name: 'profile_password',
        methods: ['POST'],
    )]
    public function changePassword(
        string $adminPrefix,
        Request $request,
        UserActivityRepository $activity,
        UserPasswordHasherInterface $hasher,
        EntityManagerInterface $em,
    ): Response {
        $this->ensureValidPrefix($adminPrefix);
        $user = $this->currentUser();

        // Google-only accounts have no password to change; the page hides
        // the card, this guards hand-crafted requests.
        if ('' === (string) $user->getPassword()) {
            throw $this->createNotFoundException();
        }

        $form = $this->createForm(ProfilePasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setPassword($hasher->hashPassword($user, (string) $form->get('plainPassword')->getData()));
            // A password change is a global revocation point: drop the
            // trusted-device cookies so a stolen device loses its 2FA skip.
            $user->bumpTrustedTokenVersion();
            $em->flush();

            $this->logger->info('Password changed from the profile page.', ['user' => $user->getEmail()]);
            $this->addFlash('success', 'admin.profile.password.success');

            return $this->redirectToRoute('admin_profile', ['adminPrefix' => $adminPrefix], Response::HTTP_SEE_OTHER);
        }

        return $this->renderProfile($adminPrefix, $user, $activity, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Route(
        path: [
            'fr' => '/profil/codes-recuperation.pdf',
            'en' => '/profile/recovery-codes.pdf',
        ],
        name: 'profile_recovery_codes_pdf',
        methods: ['GET'],
    )]
    public function recoveryCodesPdf(string $adminPrefix, Request $request, \App\Auth\Service\RecoveryCodesPdfRenderer $renderer): Response
    {
        $this->ensureValidPrefix($adminPrefix);

        // Only during the one-time display window: the plain codes exist
        // nowhere else (DB stores hashes) and die with the dismiss action.
        $codes = $request->getSession()->get('two_factor_recovery_codes');
        if (!\is_array($codes) || [] === $codes) {
            throw $this->createNotFoundException('No recovery codes pending display.');
        }

        $response = new Response($renderer->render(array_values($codes), (string) $this->currentUser()->getEmail()));
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', \Symfony\Component\HttpFoundation\HeaderUtils::makeDisposition(
            \Symfony\Component\HttpFoundation\HeaderUtils::DISPOSITION_ATTACHMENT,
            'codes-recuperation-relocation-in-paris.pdf',
        ));
        // Jamais en cache : contenu ultra-sensible, URL identique pour tous
        // les comptes. LiteSpeed (o2switch) ignore Cache-Control private,
        // d'où le header propriétaire en plus, sinon un PDF pourrait être
        // resservi au staff suivant.
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-LiteSpeed-Cache-Control', 'no-cache');

        return $response;
    }

    private function renderProfile(
        string $adminPrefix,
        User $user,
        UserActivityRepository $activity,
        FormInterface $passwordForm,
        int $status = Response::HTTP_OK,
    ): Response {
        $profile = $this->users->findByUniqueId((string) $user->getUniqueId())
            ?? throw $this->createNotFoundException();

        // L'activité suit l'accès à la section : on ne charge même pas les
        // leads/dossiers d'un membre qui n'a plus la section correspondante
        // (le template masque déjà les blocs, ici on évite la requête).
        return $this->render('admin/profile/show.html.twig', [
            'adminPrefix' => $adminPrefix,
            'profile' => $profile,
            'staffFunctions' => $user->getStaffFunctions(),
            'assignedLeads' => $this->isGranted('ROLE_SECTION_CONTACTS') ? $activity->assignedLeads($profile->id) : [],
            'managedDossiers' => $this->isGranted('ROLE_SECTION_DOSSIERS') ? $activity->managedDossiers($profile->id) : [],
            'passwordForm' => $passwordForm->createView(),
        ], new Response(status: $status));
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function ensureValidPrefix(string $adminPrefix): void
    {
        if (!hash_equals($this->adminPathPrefix, $adminPrefix)) {
            throw $this->createNotFoundException();
        }
    }
}
