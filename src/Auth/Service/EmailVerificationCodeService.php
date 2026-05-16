<?php

declare(strict_types=1);

namespace App\Auth\Service;

use App\Auth\Domain\Language;
use App\Auth\Entity\EmailVerificationRequest;
use App\Auth\Entity\User;
use App\Auth\Repository\EmailVerificationRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Generates and verifies the 6-digit OTP that confirms ownership of the user's
 * email during registration. Replaces the SymfonyCasts signed-link approach with
 * a short numeric code the user types back into the form on the same device —
 * better UX (no email-app context switch, paste-friendly on mobile), better
 * resilience against email-scanner pre-fetches that auto-click signed URLs.
 *
 * The code is hashed (argon2id via the PasswordHasher factory) before persistence
 * so a DB leak does not expose the codes; verification runs in constant time.
 * The hash, expiration and attempt counter live in a dedicated
 * {@see EmailVerificationRequest} row — see that entity for the rationale.
 * Lifetime is bounded to 15 minutes; rate-limiting on the verify endpoint is the
 * responsibility of the controller.
 */
final readonly class EmailVerificationCodeService
{
    private const CODE_LIFETIME = '+15 minutes';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private EmailVerificationRequestRepository $requestRepository,
        private MailerInterface $mailer,
        private PasswordHasherFactoryInterface $passwordHasherFactory,
        private TranslatorInterface $translator,
    ) {
    }

    private function hasher(): \Symfony\Component\PasswordHasher\PasswordHasherInterface
    {
        return $this->passwordHasherFactory->getPasswordHasher(User::class);
    }

    /**
     * Generates a fresh 6-digit code, replaces any previous pending request for
     * the same user, persists the new request and emails the plaintext code.
     *
     * Returns the plaintext code only as a side-product for tests; production
     * code never reads this — the code only lives on the wire to the inbox.
     */
    public function generateAndSend(User $user): string
    {
        $code = str_pad((string) random_int(0, 999_999), 6, '0', \STR_PAD_LEFT);

        // Replace any previous pending request — the unique index on user_id
        // would otherwise reject the new insert. Flush the DELETE first so it
        // doesn't get reordered after the INSERT in the same transaction.
        $this->requestRepository->removeForUser($user);

        $request = new EmailVerificationRequest(
            user: $user,
            codeHash: $this->hasher()->hash($code),
            expiresAt: new \DateTimeImmutable(self::CODE_LIFETIME),
        );
        $this->requestRepository->save($request);

        $locale = ($user->getLanguage() ?? Language::Fr)->value;

        $this->mailer->send(
            (new TemplatedEmail())
                ->from(new Address('no-reply@relocation-in-paris.fr', 'Relocation in Paris'))
                ->to((string) $user->getEmail())
                ->subject($this->translator->trans('register.confirmationEmail.subject', [], null, $locale))
                ->htmlTemplate('public/auth/register_email.html.twig')
                ->locale($locale)
                ->context([
                    'code' => $code,
                    'firstName' => $user->getFirstName(),
                ]),
        );

        return $code;
    }

    /**
     * Constant-time verification of the user-supplied code against the stored
     * hash. On success, flips isVerified=true and removes the request so the
     * same code cannot be replayed. On failure the attempt is counted; if the
     * cap is reached the request is also removed (forces the user to resend).
     */
    public function verify(User $user, string $code): bool
    {
        $request = $this->requestRepository->findOneForUser($user);
        if (null === $request) {
            return false;
        }

        $now = new \DateTimeImmutable();
        if ($request->isExpired($now)) {
            $this->requestRepository->remove($request);

            return false;
        }

        $request->recordAttempt($now);

        if (!$this->hasher()->verify($request->getCodeHash(), $code)) {
            if ($request->hasReachedMaxAttempts()) {
                $this->requestRepository->remove($request);

                return false;
            }
            $this->entityManager->flush();

            return false;
        }

        $user->setVerified(true);
        $this->requestRepository->remove($request, flush: false);
        $this->entityManager->flush();

        return true;
    }
}
