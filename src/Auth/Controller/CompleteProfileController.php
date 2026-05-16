<?php

namespace App\Auth\Controller;

use App\Auth\Attribute\AllowIncompleteProfile;
use App\Auth\Domain\Language;
use App\Auth\Domain\Register\Account;
use App\Auth\Entity\User;
use App\Auth\Form\Register\AccountStepType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Final step of the Google-driven registration: an authenticated user whose profile is
 * still incomplete (no phoneNumber/nationality and no terms consent yet) must fill the
 * Account step before they can use the rest of the app.
 *
 * Reached by:
 *   - Google sign-in (new user, profile defaults to incomplete).
 *   - Any subsequent request from a user with isProfileComplete=false — the
 *     ProfileCompletionListener force-redirects them here.
 *
 * Submission flips isProfileComplete=true and clears the gate.
 */
final class CompleteProfileController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(
        path: [
            'fr' => '/{_locale}/inscription/profil',
            'en' => '/{_locale}/register/complete',
        ],
        name: 'app_register_complete',
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[AllowIncompleteProfile]
    public function complete(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        if ($user->isProfileComplete()) {
            return $this->redirectToRoute('app_home');
        }

        $account = new Account(
            phoneNumber: $user->getPhoneNumber(),
            nationality: $user->getNationality(),
            situation: $user->getSituation(),
        );

        $form = $this->createForm(AccountStepType::class, $account, [
            'validation_groups' => ['account'],
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user
                ->setPhoneNumber($account->phoneNumber)
                ->setNationality($account->nationality)
                ->setSituation($account->situation)
                ->setLanguage(Language::tryFrom($request->getLocale()) ?? $user->getLanguage())
                ->setProfileComplete(true)
            ;
            $this->entityManager->flush();

            return $this->redirectToRoute('app_home');
        }

        $statusCode = $form->isSubmitted() && !$form->isValid() ? 422 : 200;

        return $this->render('public/auth/complete_profile.html.twig', [
            'form' => $form,
        ], new Response(null, $statusCode));
    }
}
