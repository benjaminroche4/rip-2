<?php

namespace App\Tests\Auth;

use App\Auth\Entity\ResetPasswordRequest;
use App\Auth\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Soft account suspension contract: a suspended account cannot log in
 * (UserChecker) and any open session dies on the next request
 * (User::isEqualTo compares the flag). Resuming restores the login.
 */
final class SuspensionTest extends WebTestCase
{
    private const PASSWORD = 'password';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $adminPrefix;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();

        $this->adminPrefix = (string) $container->getParameter('admin_path_prefix');

        $this->em = $container->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.ResetPasswordRequest::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@suspension-test.local')->execute();
    }

    public function testSuspendedAccountCannotLogInThroughTheForm(): void
    {
        $this->seedUser('suspended@suspension-test.local', suspended: true);

        $this->client->request('GET', '/fr/connexion');
        self::assertResponseIsSuccessful();

        $this->client->submitForm('Se connecter', [
            '_username' => 'suspended@suspension-test.local',
            '_password' => self::PASSWORD,
        ]);

        // form_login redirects back to the login page with the
        // account-status error.
        self::assertResponseRedirects('/fr/connexion');
        $this->client->followRedirect();
        self::assertSelectorExists('[role="alert"]');
        self::assertStringContainsString('suspendu', (string) $this->client->getResponse()->getContent());
    }

    public function testOpenSessionDiesWhenTheAccountGetsSuspended(): void
    {
        $user = $this->seedUser('staff@suspension-test.local', ['ROLE_SECTION_TOOLS']);
        $this->client->loginUser($user);

        $url = '/fr/'.$this->adminPrefix.'/admin/outils';

        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        // Suspension while the session is open.
        $user->setSuspended(true);
        $this->em->flush();

        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(302, 'The stale session must be invalidated, not honored.');
    }

    public function testResumedAccountCanLogInAgain(): void
    {
        $user = $this->seedUser('resumed@suspension-test.local', ['ROLE_SECTION_TOOLS'], suspended: true);

        $user->setSuspended(false);
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('GET', '/fr/'.$this->adminPrefix.'/admin/outils');
        self::assertResponseIsSuccessful();
    }

    /**
     * @param list<string> $roles
     */
    private function seedUser(string $email, array $roles = [], bool $suspended = false): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get('security.user_password_hasher');

        $user = (new User())
            ->setEmail($email)
            ->setFirstName('Test')
            ->setLastName('Staff')
            ->setRoles($roles)
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)
            ->setVerified(true)
            ->setSuspended($suspended);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
