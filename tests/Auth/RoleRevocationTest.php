<?php

namespace App\Tests\Auth;

use App\Auth\Entity\ResetPasswordRequest;
use App\Auth\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Locks down instant access revocation: roles are snapshotted in the session
 * at login, and User::isEqualTo() invalidates the session as soon as the DB
 * roles diverge, so a revoked staff member loses the back-office on their
 * very next request, without waiting for a re-login.
 */
final class RoleRevocationTest extends WebTestCase
{
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
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@role-revocation-test.local')->execute();
    }

    public function testRevokedSectionAccessIsLostOnTheNextRequest(): void
    {
        $user = $this->seedUser('staff@role-revocation-test.local', ['ROLE_SECTION_TOOLS']);
        $this->client->loginUser($user);

        $url = '/fr/'.$this->adminPrefix.'/admin/outils';

        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        // Revocation while the session is open.
        $user->setRoles([]);
        $this->em->flush();

        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(302, 'The stale session must be invalidated, not honored.');
    }

    public function testGrantedAccessRequiresAFreshLogin(): void
    {
        $user = $this->seedUser('newstaff@role-revocation-test.local');
        $this->client->loginUser($user);

        $url = '/fr/'.$this->adminPrefix.'/admin/outils';

        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(403);

        // Grant while the session is open: the old session dies (302 to
        // login) instead of keeping a stale role set.
        $user->setRoles(['ROLE_SECTION_TOOLS']);
        $this->em->flush();

        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(302);

        // A fresh login picks up the new access. Reload through the current
        // container: the kernel reboots between requests, the old instance
        // is no longer managed.
        $fresh = static::getContainer()->get('doctrine.orm.entity_manager')
            ->getRepository(User::class)
            ->findOneBy(['email' => 'newstaff@role-revocation-test.local']);
        self::assertNotNull($fresh);
        $this->client->loginUser($fresh);
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
    }

    public function testUntouchedRolesKeepTheSessionAlive(): void
    {
        $user = $this->seedUser('stable@role-revocation-test.local', ['ROLE_SECTION_TOOLS']);
        $this->client->loginUser($user);

        $url = '/fr/'.$this->adminPrefix.'/admin/outils';

        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
    }

    /**
     * @param list<string> $roles
     */
    private function seedUser(string $email, array $roles = []): User
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
            ->setVerified(true);
        $user->setPassword($hasher->hashPassword($user, 'password'));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
