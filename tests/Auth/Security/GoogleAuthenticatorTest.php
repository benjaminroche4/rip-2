<?php

namespace App\Tests\Auth\Security;

use App\Auth\Entity\User;
use App\Auth\Repository\UserRepository;
use App\Auth\Security\GoogleAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Http\Authenticator\InteractiveAuthenticatorInterface;

/**
 * Pure unit test covering the InteractiveAuthenticator contract — kept separate
 * from the integration suite so a kernel boot isn't required.
 */
final class GoogleAuthenticatorContractTest extends TestCase
{
    /**
     * Locks down the interactive flag of GoogleAuthenticator. Required so
     * Symfony's AuthenticatorManager dispatches InteractiveLoginEvent on
     * Google logins (otherwise UpdateLastLoginListener would never fire).
     */
    public function testIsInteractiveSoSecurityDispatchesInteractiveLoginEvent(): void
    {
        $reflection = new \ReflectionClass(GoogleAuthenticator::class);

        self::assertTrue(
            $reflection->implementsInterface(InteractiveAuthenticatorInterface::class),
            'GoogleAuthenticator must implement InteractiveAuthenticatorInterface so InteractiveLoginEvent fires.',
        );

        $isInteractive = $reflection->getMethod('isInteractive')->invoke(
            $reflection->newInstanceWithoutConstructor(),
        );
        self::assertTrue($isInteractive, 'isInteractive() must return true.');
    }
}

/**
 * Integration tests covering the find-or-create branch of GoogleAuthenticator.
 *
 * Goes through the real Doctrine + Repository + AvatarDownloader services so the
 * test exercises the same DB schema and same flush path used in production. The
 * full OAuth round-trip (client redirect, token exchange, GoogleUser fetch) is
 * skipped here — the authenticator's authenticate() is already covered indirectly
 * by LoginRedirectTest / CompleteProfileControllerTest; what matters at this
 * level is the User-entity bookkeeping the helper performs on the three branches.
 */
final class GoogleAuthenticatorTest extends WebTestCase
{
    private GoogleAuthenticator $authenticator;
    private EntityManagerInterface $em;
    private UserRepository $userRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $em = $container->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->userRepository = $container->get(UserRepository::class);

        $authenticator = $container->get(GoogleAuthenticator::class);
        self::assertInstanceOf(GoogleAuthenticator::class, $authenticator);
        $this->authenticator = $authenticator;

        $this->em->createQuery('DELETE FROM '.\App\Auth\Entity\ResetPasswordRequest::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class)->execute();
    }

    public function testNewUserIsCreatedIncompleteButVerified(): void
    {
        $user = $this->authenticator->findOrCreateUser(
            googleId: 'gg-id-new-123',
            email: 'fresh@example.com',
            firstName: 'Jane',
            lastName: 'Doe',
            fullName: 'Jane Doe',
            avatarUrl: null,
            locale: 'en',
        );

        self::assertNotNull($user->getId(), 'New user must be persisted.');
        self::assertSame('fresh@example.com', $user->getEmail());
        self::assertSame('gg-id-new-123', $user->getGoogleId());
        self::assertSame('Jane', $user->getFirstName());
        self::assertSame('Doe', $user->getLastName());
        self::assertFalse($user->isProfileComplete(), 'Google sign-up has no phone/situation yet.');
        self::assertTrue($user->isVerified(), 'Google has already validated the email.');
        self::assertContains('ROLE_USER', $user->getRoles());
        self::assertSame(\App\Auth\Domain\Language::En, $user->getLanguage(), 'Language captured from request locale.');
    }

    public function testExistingGoogleIdReturnsLinkedUserWithoutCreatingNew(): void
    {
        $existing = (new User())
            ->setEmail('returning@example.com')
            ->setGoogleId('gg-id-existing-1')
            ->setFirstName('Re')
            ->setLastName('Turn')
            ->setCreatedAt(new \DateTimeImmutable('-1 month'))
            ->setProfileComplete(true)
            ->setVerified(true);
        $this->em->persist($existing);
        $this->em->flush();

        $user = $this->authenticator->findOrCreateUser(
            googleId: 'gg-id-existing-1',
            email: 'whatever-google-returns@example.com',
            firstName: 'Ignored',
            lastName: 'Ignored',
            fullName: null,
            avatarUrl: null,
        );

        self::assertSame($existing->getId(), $user->getId(), 'Must return the existing matched user.');
        self::assertSame('returning@example.com', $user->getEmail(), 'Email is not overwritten on subsequent logins.');
        self::assertTrue($user->isProfileComplete(), 'Profile completion state is preserved.');
        self::assertCount(1, $this->userRepository->findAll());
    }

    public function testExistingEmailMatchGetsGoogleIdLinkedNotDuplicated(): void
    {
        $existing = (new User())
            ->setEmail('classic@example.com')
            ->setFirstName('Class')
            ->setLastName('Ic')
            ->setCreatedAt(new \DateTimeImmutable('-2 months'))
            ->setProfileComplete(true)
            ->setVerified(true);
        $this->em->persist($existing);
        $this->em->flush();

        self::assertNull($existing->getGoogleId(), 'Sanity: classic account has no googleId.');

        $user = $this->authenticator->findOrCreateUser(
            googleId: 'gg-id-link-2',
            email: 'classic@example.com',
            firstName: 'Class',
            lastName: 'Ic',
            fullName: null,
            avatarUrl: null,
        );

        self::assertSame($existing->getId(), $user->getId(), 'Must adopt the classic-flow user, not duplicate.');
        self::assertSame('gg-id-link-2', $user->getGoogleId(), 'googleId is now linked.');
        self::assertTrue($user->isProfileComplete(), 'Existing completion state is untouched.');
        self::assertCount(1, $this->userRepository->findAll());
    }

    public function testFullNameIsSplitWhenFirstAndLastAreMissing(): void
    {
        $user = $this->authenticator->findOrCreateUser(
            googleId: 'gg-id-split',
            email: 'noname@example.com',
            firstName: null,
            lastName: null,
            fullName: 'Marie Curie',
            avatarUrl: null,
        );

        self::assertSame('Marie', $user->getFirstName());
        self::assertSame('Curie', $user->getLastName());
    }
}
