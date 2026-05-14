<?php

namespace App\Auth\Controller;

use App\Auth\Domain\Register\RegisterDto;
use App\Auth\Entity\User;
use App\Auth\Form\Register\RegisterFlowType;
use App\Auth\Repository\UserRepository;
use App\Auth\Service\EmailVerifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Flow\DataStorage\SessionDataStorage;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;

/**
 * Multi-step registration flow + email verification.
 *
 * Flow:
 *   1. /inscription (GET)  → step 1 (Personal: firstName, lastName, email)
 *   2. /inscription (POST) → step 1 validated, advances to step 2 (Account: password, terms)
 *   3. /inscription (POST) → step 2 validated → persist user + send confirmation email →
 *                            redirect to /inscription/verification-email
 *   4. /inscription/verifier-email/{id} (GET) → validates signature, flips isVerified, redirects to /connexion
 *
 * Intermediate data lives in the session under the `register_flow` key via SessionDataStorage,
 * so a page reload between steps preserves the user's input. Cleared once the user submits the
 * final step.
 */
final class RegisterController extends AbstractController
{
    private const SESSION_KEY = 'register_flow';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EmailVerifier $emailVerifier,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly RequestStack $requestStack,
    ) {
    }

    #[Route(
        path: [
            'fr' => '/{_locale}/inscription',
            'en' => '/{_locale}/register',
        ],
        name: 'app_register',
    )]
    public function register(Request $request): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        $flow = $this->createForm(RegisterFlowType::class, new RegisterDto(), [
            'data_storage' => new SessionDataStorage(self::SESSION_KEY, $this->requestStack),
        ]);
        $flow->handleRequest($request);

        if ($flow->isSubmitted() && $flow->isValid() && $flow->isFinished()) {
            /** @var RegisterDto $data */
            $data = $flow->getData();

            // The flow's `auto_reset` cleared the session data when the finish button was
            // handled, so re-rendering the current step would mix a stale form with a fresh
            // cursor. Build a clean step-1 form and surface the duplicate as a flash error.
            $existing = $this->userRepository->findOneBy(['email' => $data->personal->email]);
            if (null !== $existing) {
                $this->addFlash('register_error', 'register.email.alreadyUsed');

                $freshFlow = $this->createForm(RegisterFlowType::class, new RegisterDto(), [
                    'data_storage' => new SessionDataStorage(self::SESSION_KEY, $this->requestStack),
                ]);

                return $this->renderStep($freshFlow)->setStatusCode(422);
            }

            $user = (new User())
                ->setEmail((string) $data->personal->email)
                ->setFirstName((string) $data->personal->firstName)
                ->setLastName((string) $data->personal->lastName)
                ->setCreatedAt(new \DateTimeImmutable())
            ;
            $user->setPassword($this->passwordHasher->hashPassword($user, (string) $data->personal->plainPassword));

            $this->entityManager->persist($user);
            $this->entityManager->flush();

            $this->emailVerifier->sendEmailConfirmation(
                'app_register_verify_email',
                $user,
                $this->emailVerifier->buildRegistrationEmail($user),
            );

            $this->requestStack->getSession()->set('register_check_email', $user->getEmail());

            return $this->redirectToRoute('app_register_check_email');
        }

        return $this->renderStep($flow);
    }

    #[Route(
        path: [
            'fr' => '/{_locale}/inscription/verification-email',
            'en' => '/{_locale}/register/check-email',
        ],
        name: 'app_register_check_email',
    )]
    public function checkEmail(): Response
    {
        $email = $this->requestStack->getSession()->get('register_check_email');
        if (null === $email) {
            return $this->redirectToRoute('app_register');
        }

        return $this->render('public/auth/register_check_email.html.twig', [
            'email' => $email,
        ]);
    }

    #[Route(
        path: [
            'fr' => '/{_locale}/inscription/verifier-email/{id}',
            'en' => '/{_locale}/register/verify-email/{id}',
        ],
        name: 'app_register_verify_email',
        requirements: ['id' => '\d+'],
    )]
    public function verifyUserEmail(Request $request, int $id): Response
    {
        $user = $this->userRepository->find($id);
        if (null === $user) {
            $this->addFlash('register_error', 'register.verify.invalidLink');

            return $this->redirectToRoute('app_register');
        }

        try {
            $this->emailVerifier->handleEmailConfirmation($request, $user);
        } catch (VerifyEmailExceptionInterface $exception) {
            $this->addFlash('register_error', $exception->getReason());

            return $this->redirectToRoute('app_register');
        }

        $this->addFlash('register_success', 'register.verify.success');

        return $this->redirectToRoute('app_login');
    }

    private function renderStep(FormInterface $flow): Response
    {
        $statusCode = $flow->isSubmitted() && !$flow->isValid() ? 422 : 200;

        // getStepForm() advances the FormFlow cursor (handle the clicked button) before
        // returning the form for the current step. Reading the cursor afterwards reflects
        // the *new* step, which is what the template needs to render.
        /** @var \Symfony\Component\Form\Flow\FormFlowInterface $flow */
        $stepForm = $flow->getStepForm();
        $cursor = $flow->getCursor();

        return $this->render('public/auth/register.html.twig', [
            'form' => $stepForm,
            'currentStep' => $cursor->getCurrentStep(),
            'stepIndex' => $cursor->getStepIndex(),
            'stepCount' => $cursor->getTotalSteps(),
            'isFirstStep' => $cursor->isFirstStep(),
            'isLastStep' => $cursor->isLastStep(),
        ], new Response(null, $statusCode));
    }
}
