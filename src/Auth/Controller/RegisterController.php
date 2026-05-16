<?php

namespace App\Auth\Controller;

use App\Auth\Domain\Language;
use App\Auth\Domain\Register\RegisterDto;
use App\Auth\Entity\User;
use App\Auth\Form\Register\RegisterFlowType;
use App\Auth\Repository\UserRepository;
use App\Auth\Service\EmailVerificationCodeService;
use Doctrine\ORM\EntityManagerInterface;
use Presta\SitemapBundle\Sitemap\Url\UrlConcrete;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\Flow\DataStorage\SessionDataStorage;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Multi-step registration flow.
 *
 *   1. /inscription (GET)  → step 1 (Personal: firstName, lastName, email, password)
 *   2. /inscription (POST) → step 1 validated (email uniqueness, password strength,
 *      …) → advances to step 2 (Account: phone, nationality, situation, terms)
 *   3. /inscription (POST) → step 2 validated → persist user + generate 6-digit OTP
 *      sent by email → redirect to {@see EmailVerificationController::verify}.
 *
 * Intermediate data lives in the session under the `register_flow` key via
 * SessionDataStorage, so a page reload between steps preserves the user's input.
 * The session is cleared once the final step is submitted; the pending email is
 * stashed under `register_check_email` so the OTP verification controller knows
 * which user is waiting on a code.
 */
final class RegisterController extends AbstractController
{
    private const SESSION_KEY = 'register_flow';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EmailVerificationCodeService $codeService,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly RequestStack $requestStack,
        #[Autowire(service: 'limiter.register_attempts')]
        private readonly RateLimiterFactory $registerLimiter,
        #[Autowire(service: 'limiter.email_verification_resend')]
        private readonly RateLimiterFactory $resendLimiter,
    ) {
    }

    #[Route(
        path: [
            'fr' => '/{_locale}/inscription',
            'en' => '/{_locale}/register',
        ],
        name: 'app_register',
        options: [
            'sitemap' => [
                'priority' => 0.3,
                'changefreq' => UrlConcrete::CHANGEFREQ_YEARLY,
                'lastmod' => new \DateTime('2026-04-16'),
            ],
        ],
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

        // Intermediate step validated (next/previous) — PRG redirect so Turbo Drive
        // performs the swap to the new step. Without the redirect, Turbo ignores a
        // 200 response to a successful POST and keeps the previous step on screen
        // (the user only sees the new step after a manual refresh).
        //
        // getStepForm() must be called first to handle the clicked navigator button:
        // that's what advances the cursor and persists the new step in the session.
        // Without it, the redirect would re-render the previous step.
        if ($flow->isSubmitted() && $flow->isValid() && !$flow->isFinished()) {
            /** @var \Symfony\Component\Form\Flow\FormFlowInterface $flow */
            $flow->getStepForm();

            return $this->redirectToRoute('app_register', [], Response::HTTP_SEE_OTHER);
        }

        if ($flow->isSubmitted() && $flow->isValid() && $flow->isFinished()) {
            // Per-IP throttle on full-flow completions — caps bot signups that
            // would otherwise burn the SMTP quota on OTP emails.
            $limit = $this->registerLimiter->create($request->getClientIp() ?? 'unknown')->consume();
            if (!$limit->isAccepted()) {
                $this->addFlash('register_error', 'register.tooManyAttempts');

                return $this->renderStep($flow)->setStatusCode(429);
            }

            /** @var RegisterDto $data */
            $data = $flow->getData();

            // Race-condition safety net: step-1 UniqueUserEmail already rejects taken
            // addresses, but somebody could race a signup between the two steps.
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
                ->setPhoneNumber($data->account->phoneNumber)
                ->setNationality($data->account->nationality)
                ->setSituation($data->account->situation)
                ->setLanguage(Language::tryFrom($request->getLocale()))
                ->setCreatedAt(new \DateTimeImmutable())
                ->setProfileComplete(true)
                ->setRoles(['ROLE_USER'])
            ;
            $user->setPassword($this->passwordHasher->hashPassword($user, (string) $data->personal->plainPassword));

            $this->entityManager->persist($user);
            $this->entityManager->flush();

            $this->codeService->generateAndSend($user);
            // Consume the resend slot the initial email just used, so a click
            // on "Renvoyer un code" within 60 s is rejected server-side, not
            // only hidden by the JS countdown.
            $this->resendLimiter
                ->create($user->getId().':'.($request->getClientIp() ?? 'unknown'))
                ->consume();
            $session = $this->requestStack->getSession();
            $session->set('register_check_email', $user->getEmail());
            // Seed the UI countdown so the verify page renders the button
            // disabled with the matching remaining-seconds hint.
            $session->set('otp_resend_available_at', time() + 60);

            return $this->redirectToRoute('app_register_verify_code');
        }

        return $this->renderStep($flow);
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
