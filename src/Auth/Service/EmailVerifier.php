<?php

namespace App\Auth\Service;

use App\Auth\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

/**
 * Thin wrapper around {@see VerifyEmailHelperInterface} that handles the two operations
 * the registration flow needs: send a signed confirmation email, and validate the link
 * the user clicks. Marks the user as verified on successful validation.
 *
 * The helper signs URLs with {@see Symfony\Component\HttpFoundation\UriSigner}, so tampering
 * with the token or email invalidates the signature. Tokens expire after the lifetime
 * configured in `config/packages/verify_email.yaml` (default: 1 hour).
 */
final class EmailVerifier
{
    public function __construct(
        private readonly VerifyEmailHelperInterface $verifyEmailHelper,
        private readonly MailerInterface $mailer,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function sendEmailConfirmation(string $verifyEmailRouteName, User $user, TemplatedEmail $email): void
    {
        $signatureComponents = $this->verifyEmailHelper->generateSignature(
            $verifyEmailRouteName,
            (string) $user->getId(),
            (string) $user->getEmail(),
            ['id' => $user->getId()],
        );

        $email->context(array_merge($email->getContext(), [
            'signedUrl' => $signatureComponents->getSignedUrl(),
            'expiresAtMessageKey' => $signatureComponents->getExpirationMessageKey(),
            'expiresAtMessageData' => $signatureComponents->getExpirationMessageData(),
        ]));

        $this->mailer->send($email);
    }

    /**
     * Validates the signed URL on the verification request and flips the user's verified flag.
     *
     * @throws VerifyEmailExceptionInterface when the signature is invalid or expired
     */
    public function handleEmailConfirmation(Request $request, User $user): void
    {
        $this->verifyEmailHelper->validateEmailConfirmationFromRequest(
            $request,
            (string) $user->getId(),
            (string) $user->getEmail(),
        );

        $user->setVerified(true);

        $this->entityManager->persist($user);
        $this->entityManager->flush();
    }

    public function buildRegistrationEmail(User $user): TemplatedEmail
    {
        return (new TemplatedEmail())
            ->from(new Address('no-reply@relocation-in-paris.fr', 'Relocation in Paris'))
            ->to((string) $user->getEmail())
            ->subject('Confirmez votre adresse email')
            ->htmlTemplate('public/auth/register_email.html.twig');
    }
}
