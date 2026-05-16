<?php

declare(strict_types=1);

namespace App\Auth\Controller;

use App\Auth\Attribute\AllowUnverifiedEmail;
use App\Auth\Domain\Register\VerificationCodeSubmission;
use App\Auth\Entity\User;
use App\Auth\Form\Register\VerificationCodeFormType;
use App\Auth\Repository\UserRepository;
use App\Auth\Service\EmailVerificationCodeService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * OTP email-verification flow that replaces the SymfonyCasts signed-link bundle.
 *
 * Why OTP > signed link here:
 *  - the user stays on the same device (no email-app context switch on mobile),
 *  - resilient to corporate email scanners that pre-fetch URLs to scan for phishing
 *    and would otherwise auto-burn a signed link before the user opens the mail,
 *  - paste-friendly and password-manager-friendly (autocomplete="one-time-code"),
 *  - after a correct code we auto-login the user — no need to re-type credentials
 *    they just typed 90 seconds ago.
 *
 * Flow:
 *   /inscription/verification (GET)         → render the 6-digit form
 *   /inscription/verification (POST)        → validate, auto-login, redirect home
 *   /inscription/verification/renvoyer (POST) → regenerate + email a fresh code
 */
#[AllowUnverifiedEmail]
final class EmailVerificationController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EmailVerificationCodeService $codeService,
        private readonly RequestStack $requestStack,
        private readonly Security $security,
        #[Autowire(service: 'limiter.email_verification_attempts')]
        private readonly RateLimiterFactory $attemptsLimiter,
        #[Autowire(service: 'limiter.email_verification_resend')]
        private readonly RateLimiterFactory $resendLimiter,
        #[Autowire(service: 'monolog.logger.security')]
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(
        path: [
            'fr' => '/{_locale}/inscription/verification',
            'en' => '/{_locale}/register/verification',
        ],
        name: 'app_register_verify_code',
        methods: ['GET', 'POST'],
    )]
    public function verify(Request $request): Response
    {
        $user = $this->pendingUser();
        if (null === $user) {
            return $this->redirectToRoute('app_register');
        }
        if ($user->isVerified()) {
            return $this->redirectToRoute('app_home');
        }

        $submission = new VerificationCodeSubmission();
        $form = $this->createForm(VerificationCodeFormType::class, $submission);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $limit = $this->attemptsLimiter->create($this->limiterKey($request, $user))->consume();
            if (!$limit->isAccepted()) {
                $this->logger->warning('OTP verification throttled', [
                    'user_id' => $user->getId(),
                    'ip' => $request->getClientIp(),
                ]);
                $form->get('code')->addError(new FormError('register.verify.code.tooManyAttempts'));

                return $this->renderForm($form, 429);
            }

            if (!$this->codeService->verify($user, (string) $submission->code)) {
                // Log every failed attempt so a brute-force pattern shows up in
                // the security channel even before the rate-limiter kicks in.
                $this->logger->warning('OTP verification failed (wrong or expired code)', [
                    'user_id' => $user->getId(),
                    'ip' => $request->getClientIp(),
                ]);
                $form->get('code')->addError(new FormError('register.verify.code.invalid'));

                return $this->renderForm($form, 422);
            }

            $this->logger->info('Email verified via OTP', [
                'user_id' => $user->getId(),
            ]);
            $this->requestStack->getSession()->remove('register_check_email');

            return $this->security->login($user, 'form_login', 'main') ?? $this->redirectToRoute('app_home');
        }

        return $this->renderForm($form, $form->isSubmitted() ? 422 : 200);
    }

    #[Route(
        path: [
            'fr' => '/{_locale}/inscription/verification/renvoyer',
            'en' => '/{_locale}/register/verification/resend',
        ],
        name: 'app_register_resend_code',
        methods: ['POST'],
    )]
    public function resend(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('app_register_resend_code', (string) $request->request->get('_csrf_token'))) {
            throw new BadRequestHttpException('Invalid CSRF token.');
        }

        $user = $this->pendingUser();
        if (null === $user) {
            return $this->redirectToRoute('app_register');
        }
        if ($user->isVerified()) {
            return $this->redirectToRoute('app_home');
        }

        $limit = $this->resendLimiter->create($this->limiterKey($request, $user))->consume();
        if (!$limit->isAccepted()) {
            $this->addFlash('register_error', 'register.verify.code.resendThrottled');

            return $this->redirectToRoute('app_register_verify_code');
        }

        $this->codeService->generateAndSend($user);
        // Stamp the moment the user is allowed to resend again — the verify page
        // reads this back and feeds it to the resend-countdown Stimulus controller
        // so the button shows "(59s)" and stays disabled until the limit window
        // expires, instead of clicking-then-seeing-an-error.
        $session = $this->requestStack->getSession();
        $session->set('otp_resend_available_at', time() + 60);
        // Surface the spam reminder only AFTER the user has requested a fresh
        // code — at that point we know they couldn't find the first email and
        // the "check your spam folder" hint becomes actionable instead of noisy.
        $session->set('otp_resend_used', true);
        $this->addFlash('register_success', 'register.verify.code.resent');

        return $this->redirectToRoute('app_register_verify_code');
    }

    private function pendingUser(): ?User
    {
        $email = $this->requestStack->getSession()->get('register_check_email');
        if (!\is_string($email) || '' === $email) {
            return null;
        }

        return $this->userRepository->findOneBy(['email' => $email]);
    }

    private function renderForm(\Symfony\Component\Form\FormInterface $form, int $statusCode): Response
    {
        $session = $this->requestStack->getSession();
        $email = $session->get('register_check_email');

        // Forward the resend cooldown deadline only while it's still in the future —
        // the template uses it to seed the countdown; we drop it once expired so
        // a stale value doesn't disable the button forever.
        $resendAvailableAt = $session->get('otp_resend_available_at');
        if (!\is_int($resendAvailableAt) || $resendAvailableAt <= time()) {
            $session->remove('otp_resend_available_at');
            $resendAvailableAt = null;
        }

        return $this->render('public/auth/register_verify_code.html.twig', [
            'form' => $form,
            'email' => \is_string($email) ? $email : null,
            'resendAvailableAt' => $resendAvailableAt,
            'resendUsed' => true === $session->get('otp_resend_used'),
        ], new Response(null, $statusCode));
    }

    private function limiterKey(Request $request, User $user): string
    {
        // Combine the user id and the client IP so a shared NAT does not punish
        // legitimate users, and a single malicious IP cannot enumerate codes
        // across users.
        return $user->getId().':'.($request->getClientIp() ?? 'unknown');
    }
}
