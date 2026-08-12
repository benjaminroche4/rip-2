<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\Twig\Components\TwoFactorSettings;
use App\Auth\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use OTPHP\TOTP;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * Admin:TwoFactorSettings behaviour: enrollment only activates after a
 * valid first code, recovery codes are revealed once, disabling requires
 * the password and kills trusted devices. The stored secret is encrypted.
 */
final class TwoFactorSettingsTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private const PASSWORD = 'current-password';

    private EntityManagerInterface $em;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@two-factor-test.local')->execute();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $this->user = (new User())
            ->setEmail('staff@two-factor-test.local')
            ->setFirstName('Sam')->setLastName('Staff')
            ->setRoles(['ROLE_STAFF'])
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->user->setPassword($hasher->hashPassword($this->user, self::PASSWORD));
        $this->em->persist($this->user);
        $this->em->flush();

        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken($this->user, 'main', $this->user->getRoles()),
        );
    }

    public function testEnrollmentActivatesOnlyWithAValidCode(): void
    {
        $component = $this->mountComponent();

        $component->startEnrollment();
        self::assertSame('enrolling', $component->step);
        self::assertNotSame('', $component->enrollmentSecret);
        self::assertStringStartsWith('data:image/png;base64,', $component->getQrCodeDataUri());

        // Wrong code first: nothing is persisted.
        $component->confirmCode = '000000';
        $component->confirmEnrollment();
        self::assertSame('admin.profile.twoFactor.error.invalidCode', $component->error);
        self::assertFalse($this->user->isTotpAuthenticationEnabled());

        // Valid code generated from the enrolling secret: 2FA turns on.
        $component->confirmCode = TOTP::createFromSecret($component->enrollmentSecret)->now();
        $component->confirmEnrollment();

        self::assertSame('codes', $component->step, 'Recovery codes are revealed once.');
        self::assertCount(8, $component->recoveryCodes);
        self::assertTrue($this->user->isTotpAuthenticationEnabled());
        self::assertSame(8, $this->user->getRemainingBackupCodeCount());

        // The stored secret is encrypted at rest, never the plain base32.
        $stored = $this->storedTotpSecret();
        self::assertStringStartsWith('enc-v1:', $stored);

        $component->dismissRecoveryCodes();
        self::assertSame('idle', $component->step);
        self::assertSame([], $component->recoveryCodes);
    }

    public function testDisableRequiresTheCorrectPassword(): void
    {
        $this->enableTwoFactor();
        $component = $this->mountComponent();

        $component->askDisable();
        $component->disableCredential = 'wrong-password';
        $component->confirmDisable();

        self::assertSame('admin.profile.twoFactor.error.invalidPassword', $component->error);
        self::assertTrue($this->user->isTotpAuthenticationEnabled());

        $component->askDisable();
        $component->disableCredential = self::PASSWORD;
        $component->confirmDisable();

        self::assertFalse($this->user->isTotpAuthenticationEnabled());
        self::assertSame(0, $this->user->getRemainingBackupCodeCount());
        self::assertSame(1, $this->user->getTrustedTokenVersion(), 'Trusted devices are invalidated.');
    }

    public function testBackupCodesAreHashedAndSingleUse(): void
    {
        $this->user->setPlainTotpSecret('SECRETSECRETSECRET');
        $this->user->setPlainBackupCodes(['12345678', '87654321']);
        $this->em->flush();

        self::assertTrue($this->user->isBackupCode('12345678'));
        $this->user->invalidateBackupCode('12345678');
        self::assertFalse($this->user->isBackupCode('12345678'), 'A recovery code only works once.');
        self::assertTrue($this->user->isBackupCode('87654321'));

        // Codes are stored hashed: the raw value never sits in the column.
        $raw = $this->em->getConnection()->fetchOne('SELECT backup_codes FROM user WHERE id = ?', [$this->user->getId()]);
        self::assertStringNotContainsString('87654321', (string) $raw);
    }

    public function testRenderShowsEnableCtaWhenDisabledAndBadgeWhenEnabled(): void
    {
        $rendered = (string) $this->renderTwigComponent('Admin:TwoFactorSettings');
        self::assertStringContainsString('data-testid="two-factor-enable"', $rendered);

        $this->enableTwoFactor();
        $rendered = (string) $this->renderTwigComponent('Admin:TwoFactorSettings');
        self::assertStringContainsString('data-testid="two-factor-enabled-badge"', $rendered);
        self::assertStringContainsString('data-testid="two-factor-disable-trigger"', $rendered);
    }

    private function enableTwoFactor(): void
    {
        $this->user->setPlainTotpSecret('SECRETSECRETSECRET');
        $this->user->setPlainBackupCodes(['12345678']);
        $this->em->flush();
    }

    private function storedTotpSecret(): string
    {
        return (string) $this->em->getConnection()->fetchOne('SELECT totp_secret FROM user WHERE id = ?', [$this->user->getId()]);
    }

    private function mountComponent(): TwoFactorSettings
    {
        /** @var TwoFactorSettings $component */
        $component = $this->mountTwigComponent('Admin:TwoFactorSettings');

        return $component;
    }
}
