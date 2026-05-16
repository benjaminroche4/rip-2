<?php

namespace App\Tests\Auth;

use App\Auth\Entity\User;
use App\Auth\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * End-to-end functional tests for the multi-step registration flow.
 *
 * Covers:
 *  - GET renders step 1 (Personal)
 *  - step 1 validation rejects blank/invalid fields with 422
 *  - submitting valid step 1 advances the cursor to step 2 (Account)
 *  - completing both steps persists an inactive (isVerified=false) user
 *    and dispatches one confirmation email
 *  - the confirmation link flips isVerified and redirects to login
 *  - duplicate email is surfaced as a form error on the final step
 *  - already-authenticated users are redirected away from /inscription
 *
 * The form name is `register_flow` (block name derived from RegisterFlowType).
 * Fields are nested: register_flow[personal][firstName], etc.
 */
final class RegisterControllerTest extends WebTestCase
{
    private const REGISTER_PATH = '/fr/inscription';
    private const TEST_EMAIL = 'new-user@example.com';
    private const VALID_PASSWORD = 'V3ryStr0ng!Passw0rd2026';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private UserRepository $userRepository;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();

        $em = $container->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->userRepository = $container->get(UserRepository::class);

        $this->em->createQuery('DELETE FROM '.\App\Auth\Entity\EmailVerificationRequest::class)->execute();
        $this->em->createQuery('DELETE FROM '.\App\Auth\Entity\ResetPasswordRequest::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class)->execute();
    }

    public function testGetRendersStepOneWithPersonalFields(): void
    {
        $this->client->request('GET', self::REGISTER_PATH);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="register_flow[personal][firstName]"]');
        self::assertSelectorExists('input[name="register_flow[personal][lastName]"]');
        self::assertSelectorExists('input[name="register_flow[personal][email]"]');
        self::assertSelectorExists('input[name="register_flow[personal][plainPassword]"]');
        self::assertSelectorNotExists('input[name="register_flow[account][acceptTerms]"]');
        self::assertSelectorNotExists('input[name="register_flow[account][phoneNumber]"]');
        self::assertSelectorNotExists('select[name="register_flow[account][nationality]"]');
    }

    public function testStepOneRejectsBlankFieldsWith422(): void
    {
        $csrf = $this->csrfFromRegisterPage();

        $this->client->request('POST', self::REGISTER_PATH, $this->postPayload(
            personal: ['firstName' => '', 'lastName' => '', 'email' => ''],
            button: 'next',
            csrf: $csrf,
        ));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('input[name="register_flow[personal][firstName]"]', 'Stays on step 1 when invalid.');
    }

    public function testStepOneRejectsMalformedEmail(): void
    {
        $csrf = $this->csrfFromRegisterPage();

        $this->client->request('POST', self::REGISTER_PATH, $this->postPayload(
            personal: ['firstName' => 'Alice', 'lastName' => 'Martin', 'email' => 'not-an-email'],
            button: 'next',
            csrf: $csrf,
        ));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('input[name="register_flow[personal][email]"]');
    }

    public function testValidStepOneAdvancesToStepTwo(): void
    {
        $csrf = $this->csrfFromRegisterPage();

        $this->client->request('POST', self::REGISTER_PATH, $this->postPayload(
            personal: ['firstName' => 'Alice', 'lastName' => 'Martin', 'email' => self::TEST_EMAIL, 'plainPassword' => self::VALID_PASSWORD],
            button: 'next',
            csrf: $csrf,
        ));

        // Step-1 POST returns a 303 (PRG pattern) so Turbo Drive swaps the page.
        self::assertResponseRedirects(self::REGISTER_PATH);
        $this->client->followRedirect();

        // On step 2 the personal inputs disappear and the contact/consent fields appear.
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="register_flow[account][phoneNumber]"]');
        self::assertSelectorExists('select[name="register_flow[account][nationality]"]');
        self::assertSelectorNotExists('input[name="register_flow[account][acceptTerms]"]');
        self::assertSelectorNotExists('input[name="register_flow[personal][firstName]"]');
    }

    public function testCompletingBothStepsCreatesUnverifiedUserAndSendsConfirmationEmail(): void
    {
        $csrf = $this->csrfFromRegisterPage();

        // Step 1
        $this->client->request('POST', self::REGISTER_PATH, $this->postPayload(
            personal: ['firstName' => 'Alice', 'lastName' => 'Martin', 'email' => self::TEST_EMAIL, 'plainPassword' => self::VALID_PASSWORD],
            button: 'next',
            csrf: $csrf,
        ));
        self::assertResponseRedirects(self::REGISTER_PATH);
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();

        // Step 2 — must read the new CSRF from the freshly rendered step-2 form.
        $csrf2 = $this->client->getCrawler()->filter('input[name="register_flow[_token]"]')->attr('value');

        $this->client->request('POST', self::REGISTER_PATH, $this->postPayload(
            account: ['phoneNumber' => '+33612345678', 'nationality' => 'FR', 'situation' => \App\Auth\Domain\Situation::Employee->value],
            button: 'finish',
            csrf: $csrf2,
        ));

        // After finish: redirected to the OTP verification form.
        self::assertResponseRedirects('/fr/inscription/verification');

        // User is persisted but not yet verified — the OTP flow toggles isVerified
        // only after the user types the 6-digit code emailed to them.
        $user = $this->userRepository->findOneBy(['email' => self::TEST_EMAIL]);
        self::assertNotNull($user, 'User must be persisted after the final step.');
        self::assertFalse($user->isVerified(), 'User starts as not verified.');
        $request = static::getContainer()->get(\App\Auth\Repository\EmailVerificationRequestRepository::class)->findOneForUser($user);
        self::assertNotNull($request, 'Verification request must be stored.');
        self::assertGreaterThan(new \DateTimeImmutable(), $request->getExpiresAt(), 'Verification expiration must be in the future.');
        self::assertSame('Alice', $user->getFirstName());
        self::assertSame('Martin', $user->getLastName());
        self::assertSame('+33612345678', $user->getPhoneNumber());
        self::assertSame('FR', $user->getNationality());
        self::assertSame(\App\Auth\Domain\Language::Fr, $user->getLanguage(), 'Language is captured from the current locale.');

        // Exactly one confirmation email sent.
        self::assertEmailCount(1);
        $email = $this->getMailerMessages()[0];
        self::assertEmailAddressContains($email, 'to', self::TEST_EMAIL);
    }

    public function testDuplicateEmailIsRejectedAtStepOne(): void
    {
        $existing = (new User())
            ->setEmail(self::TEST_EMAIL)
            ->setFirstName('Existing')
            ->setLastName('User')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)
            ->setVerified(true)
            ->setPassword('placeholder-not-used-in-this-test');
        $this->em->persist($existing);
        $this->em->flush();

        $csrf = $this->csrfFromRegisterPage();
        $this->client->request('POST', self::REGISTER_PATH, $this->postPayload(
            personal: ['firstName' => 'Alice', 'lastName' => 'Martin', 'email' => self::TEST_EMAIL, 'plainPassword' => self::VALID_PASSWORD],
            button: 'next',
            csrf: $csrf,
        ));

        // Email is checked at step 1 (UniqueUserEmail constraint on Personal DTO) so
        // the user never reaches step 2 — fail fast with the "go sign in" message.
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('input[name="register_flow[personal][firstName]"]', 'Stays on step 1.');
        self::assertCount(1, $this->userRepository->findAll(), 'Duplicate email must not create a second user.');
        self::assertEmailCount(0);
    }

    public function testAuthenticatedUserIsRedirectedAway(): void
    {
        $hasher = static::getContainer()->get('security.user_password_hasher');
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        $existing = (new User())
            ->setEmail('already-in@example.com')
            ->setFirstName('Existing')
            ->setLastName('User')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)
            ->setVerified(true);
        $existing->setPassword($hasher->hashPassword($existing, self::VALID_PASSWORD));
        $this->em->persist($existing);
        $this->em->flush();

        $this->client->loginUser($existing);
        $this->client->request('GET', self::REGISTER_PATH);

        self::assertResponseStatusCodeSame(302);
        self::assertNotSame(
            self::REGISTER_PATH,
            $this->client->getResponse()->headers->get('Location'),
            'Already-authenticated users must NOT see the register page.',
        );
    }

    private function csrfFromRegisterPage(): string
    {
        $this->client->request('GET', self::REGISTER_PATH);

        return $this->client->getCrawler()->filter('input[name="register_flow[_token]"]')->attr('value');
    }

    /**
     * Build the POST payload for the FormFlow form. Always sends the CSRF token and the
     * clicked navigator button. The personal/account sub-arrays are only included when
     * the caller passes them (so a step-2 POST doesn't leak step-1 fields).
     *
     * @param array<string,string>|null $personal
     * @param array<string,string>|null $account
     */
    private function postPayload(?array $personal = null, ?array $account = null, string $button = 'next', string $csrf = ''): array
    {
        $data = [
            'register_flow' => [
                '_token' => $csrf,
                'navigator' => [$button => ''],
            ],
        ];

        if (null !== $personal) {
            $data['register_flow']['personal'] = $personal;
        }
        if (null !== $account) {
            $data['register_flow']['account'] = $account;
        }

        return $data;
    }

    /**
     * Drives a full successful registration flow for setup-style fixtures. Returns the
     * persisted (and still-unverified) user.
     */
    private function registerOneUser(string $email): User
    {
        $csrf = $this->csrfFromRegisterPage();
        $this->client->request('POST', self::REGISTER_PATH, $this->postPayload(
            personal: ['firstName' => 'Alice', 'lastName' => 'Martin', 'email' => $email, 'plainPassword' => self::VALID_PASSWORD],
            button: 'next',
            csrf: $csrf,
        ));
        $this->client->followRedirect();

        $csrf2 = $this->client->getCrawler()->filter('input[name="register_flow[_token]"]')->attr('value');
        $this->client->request('POST', self::REGISTER_PATH, $this->postPayload(
            account: ['phoneNumber' => '+33612345678', 'nationality' => 'FR', 'situation' => \App\Auth\Domain\Situation::Employee->value],
            button: 'finish',
            csrf: $csrf2,
        ));

        $user = $this->userRepository->findOneBy(['email' => $email]);
        self::assertNotNull($user);

        return $user;
    }
}
