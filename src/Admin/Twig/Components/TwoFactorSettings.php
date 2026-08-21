<?php

declare(strict_types=1);

namespace App\Admin\Twig\Components;

use App\Auth\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use Psr\Log\LoggerInterface;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfiguration;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfigurationInterface;
use Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Monolog\Attribute\WithMonologChannel;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * "Double authentification" card on the profile page. Enrollment never
 * enables anything before a first valid code proves the authenticator app
 * is set up; activation reveals single-use recovery codes exactly once;
 * disabling requires the account password (or a valid TOTP code for
 * Google-only accounts) and kills every trusted-device cookie.
 */
#[AsLiveComponent(name: 'Admin:TwoFactorSettings', template: 'components/Admin/TwoFactorSettings.html.twig')]
#[WithMonologChannel('security')]
final class TwoFactorSettings
{
    use StaffGuard;
    use ComponentToolsTrait;
    use DefaultActionTrait;

    #[LiveProp]
    public string $adminPrefix = '';

    private const BACKUP_CODE_COUNT = 8;

    /** 'idle' | 'enrolling' | 'codes' (recovery codes shown once). */
    #[LiveProp]
    public string $step = 'idle';

    /** Plain secret being enrolled; only persisted (encrypted) on confirm. */
    #[LiveProp]
    public string $enrollmentSecret = '';

    #[LiveProp(writable: true)]
    public string $confirmCode = '';

    /** True while the disable confirmation modal is open. */
    #[LiveProp]
    public bool $confirmingDisable = false;

    #[LiveProp(writable: true)]
    public string $disableCredential = '';

    /**
     * Recovery codes revealed right after activation, cleared on dismiss.
     *
     * @var list<string>
     */
    #[LiveProp]
    public array $recoveryCodes = [];

    /** Translation key of the current inline error. */
    #[LiveProp]
    public string $error = '';

    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly \Symfony\Component\HttpFoundation\RequestStack $requestStack,
        private readonly TotpAuthenticatorInterface $totpAuthenticator,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly LoggerInterface $logger,
        private readonly \Symfony\Contracts\Translation\TranslatorInterface $translator,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%two_factor_enrollment_enabled%')]
        private readonly bool $enrollmentEnabled = true,
    ) {
    }

    public function mount(): void
    {
        $this->ensureStaff();
    }

    public function isEnabled(): bool
    {
        return $this->user()->isTotpAuthenticationEnabled();
    }

    /** Coupe-circuit : l'enrôlement 2FA peut être gelé par configuration. */
    public function isEnrollmentEnabled(): bool
    {
        return $this->enrollmentEnabled;
    }

    public function getEnabledAt(): ?\DateTimeImmutable
    {
        return $this->user()->getTotpEnabledAt();
    }

    public function getRemainingBackupCodes(): int
    {
        return $this->user()->getRemainingBackupCodeCount();
    }

    public function hasPassword(): bool
    {
        return '' !== (string) $this->user()->getPassword();
    }

    /** QR to scan in the authenticator app, inlined as a data URI. */
    public function getQrCodeDataUri(): string
    {
        if ('' === $this->enrollmentSecret) {
            return '';
        }

        return new Builder(
            writer: new PngWriter(),
            data: $this->buildOtpAuthUrl(),
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: 200,
            margin: 8,
        )->build()->getDataUri();
    }

    public function getEnrollmentSecretChunks(): string
    {
        return trim(chunk_split($this->enrollmentSecret, 4, ' '));
    }

    #[LiveAction]
    public function startEnrollment(): void
    {
        $this->ensureStaff();
        // Garde serveur du coupe-circuit : le bouton grisé ne suffit pas,
        // l'endpoint /_components/ doit refuser aussi.
        if (!$this->enrollmentEnabled) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('2FA enrollment is disabled.');
        }
        if ($this->isEnabled()) {
            return;
        }

        $this->enrollmentSecret = $this->totpAuthenticator->generateSecret();
        $this->confirmCode = '';
        $this->error = '';
        $this->step = 'enrolling';
    }

    #[LiveAction]
    public function cancelEnrollment(): void
    {
        $this->ensureStaff();
        $this->clearRecoveryCodesSession();
        $this->reset();
    }

    /**
     * Activation only happens once a code generated from the enrolling
     * secret is verified: a mistyped setup can never lock the account.
     */
    #[LiveAction]
    public function confirmEnrollment(): void
    {
        $this->ensureStaff();
        $user = $this->user();
        if ($user->isTotpAuthenticationEnabled() || '' === $this->enrollmentSecret) {
            return;
        }

        if (!$this->totpAuthenticator->checkCode($this->enrollmentUser(), trim($this->confirmCode))) {
            $this->error = 'admin.profile.twoFactor.error.invalidCode';
            $this->confirmCode = '';

            return;
        }

        $plainCodes = $this->generateRecoveryCodes();
        $user->setPlainTotpSecret($this->enrollmentSecret);
        $user->setPlainBackupCodes($plainCodes);
        $this->em->flush();
        $this->logger->warning('2FA enabled from the profile page.', ['user' => $user->getEmail()]);

        $this->reset();
        $this->recoveryCodes = $plainCodes;
        // Session copy for the PDF download, alive only while the one-time
        // display window is open (cleared on dismiss). Sessionless contexts
        // (component tests) simply lose the download button, nothing else.
        try {
            $this->requestStack->getSession()->set('two_factor_recovery_codes', $plainCodes);
        } catch (\Symfony\Component\HttpFoundation\Exception\SessionNotFoundException) {
        }
        $this->step = 'codes';
        $this->dispatchBrowserEvent('toast:show', ['message' => $this->translator->trans('admin.toast.twoFactorEnabled')]);
    }

    #[LiveAction]
    public function dismissRecoveryCodes(): void
    {
        $this->ensureStaff();
        // The one-time window closes: the PDF download dies with it.
        $this->clearRecoveryCodesSession();
        $this->reset();
    }

    #[LiveAction]
    public function askDisable(): void
    {
        $this->ensureStaff();
        $this->confirmingDisable = true;
        $this->disableCredential = '';
        $this->error = '';
    }

    #[LiveAction]
    public function cancelDisable(): void
    {
        $this->ensureStaff();
        $this->confirmingDisable = false;
        $this->disableCredential = '';
        $this->error = '';
    }

    #[LiveAction]
    public function confirmDisable(): void
    {
        $this->ensureStaff();
        $user = $this->user();
        if (!$user->isTotpAuthenticationEnabled()) {
            return;
        }

        $credential = trim($this->disableCredential);
        $valid = $this->hasPassword()
            ? '' !== $credential && $this->passwordHasher->isPasswordValid($user, $credential)
            : '' !== $credential && $this->totpAuthenticator->checkCode($user, $credential);
        if (!$valid) {
            $this->error = $this->hasPassword()
                ? 'admin.profile.twoFactor.error.invalidPassword'
                : 'admin.profile.twoFactor.error.invalidCode';
            $this->disableCredential = '';
            // Traced: a stolen session hammering this field is a brute-force
            // oracle otherwise invisible in the security channel.
            $this->logger->warning('Failed 2FA disable attempt.', ['user' => $user->getEmail()]);

            return;
        }

        $user->setPlainTotpSecret(null);
        // Trusted-device cookies die with the 2FA they were bound to.
        $user->bumpTrustedTokenVersion();
        $this->em->flush();
        $this->clearRecoveryCodesSession();
        $this->logger->warning('2FA disabled from the profile page.', ['user' => $user->getEmail()]);

        $this->reset();
        $this->dispatchBrowserEvent('toast:show', ['message' => $this->translator->trans('admin.toast.twoFactorDisabled')]);
    }

    private function reset(): void
    {
        $this->step = 'idle';
        $this->enrollmentSecret = '';
        $this->confirmCode = '';
        $this->confirmingDisable = false;
        $this->disableCredential = '';
        $this->recoveryCodes = [];
        $this->error = '';
    }

    /**
     * @return list<string>
     */
    private function generateRecoveryCodes(): array
    {
        $codes = [];
        while (\count($codes) < self::BACKUP_CODE_COUNT) {
            // 8 digits: never mistakable for a 6-digit TOTP code.
            $code = (string) random_int(10000000, 99999999);
            if (!\in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /** Throwaway user carrying the enrolling secret for code verification. */
    private function enrollmentUser(): TwoFactorInterface
    {
        $email = (string) $this->user()->getEmail();
        $secret = $this->enrollmentSecret;

        return new class($email, $secret) implements TwoFactorInterface {
            public function __construct(
                private readonly string $email,
                private readonly string $secret,
            ) {
            }

            public function isTotpAuthenticationEnabled(): bool
            {
                return true;
            }

            public function getTotpAuthenticationUsername(): string
            {
                return $this->email;
            }

            public function getTotpAuthenticationConfiguration(): TotpConfigurationInterface
            {
                return new TotpConfiguration($this->secret, TotpConfiguration::ALGORITHM_SHA1, 30, 6);
            }
        };
    }

    private function buildOtpAuthUrl(): string
    {
        $issuer = 'Relocation In Paris';
        $email = (string) $this->user()->getEmail();

        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
            rawurlencode($issuer),
            rawurlencode($email),
            $this->enrollmentSecret,
            rawurlencode($issuer),
        );
    }

    private function user(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedException('Authenticated user required.');
        }

        return $user;
    }

    private function clearRecoveryCodesSession(): void
    {
        try {
            $this->requestStack->getSession()->remove('two_factor_recovery_codes');
        } catch (\Symfony\Component\HttpFoundation\Exception\SessionNotFoundException) {
        }
    }

    private function ensureStaff(): void
    {
        if (!$this->security->isGranted('ROLE_STAFF')) {
            throw new AccessDeniedException('Staff access required.');
        }
    }
}
