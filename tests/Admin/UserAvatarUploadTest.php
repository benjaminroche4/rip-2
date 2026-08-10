<?php

namespace App\Tests\Admin;

use App\Auth\Entity\ResetPasswordRequest;
use App\Auth\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Avatar upload from the admin user profile: multipart POST, CSRF-guarded,
 * re-encoded to WebP by AvatarDownloader, old file replaced.
 */
final class UserAvatarUploadTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $adminPrefix;
    private string $storageDir;

    /** @var list<string> */
    private array $createdFiles = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();

        $this->adminPrefix = (string) $container->getParameter('admin_path_prefix');
        $this->storageDir = $container->getParameter('kernel.project_dir').'/var/uploads/avatars';

        $this->em = $container->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.ResetPasswordRequest::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@avatar-upload-test.local')->execute();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function testAdminCanUploadAnAvatar(): void
    {
        $admin = $this->seedUser('admin@avatar-upload-test.local', ['ROLE_ADMIN']);
        $target = $this->seedUser('target@avatar-upload-test.local');
        $this->client->loginUser($admin);

        $this->client->request('POST', $this->avatarUrl($target), [
            '_csrf_token' => $this->csrfToken(),
        ], [
            'avatar' => $this->makePngUpload(),
        ], $this->sameOriginHeaders());

        self::assertResponseRedirects();

        $this->em->refresh($target);
        self::assertNotNull($target->getAvatarFilename());
        self::assertStringEndsWith('.webp', $target->getAvatarFilename());

        $stored = $this->storageDir.'/'.$target->getAvatarFilename();
        $this->createdFiles[] = $stored;
        self::assertFileExists($stored);
    }

    public function testUploadWithInvalidCsrfTokenIsRejected(): void
    {
        $admin = $this->seedUser('admin@avatar-upload-test.local', ['ROLE_ADMIN']);
        $target = $this->seedUser('target@avatar-upload-test.local');
        $this->client->loginUser($admin);

        $this->client->request('POST', $this->avatarUrl($target), [
            '_csrf_token' => 'forged',
        ], [
            'avatar' => $this->makePngUpload(),
        ], $this->sameOriginHeaders());

        self::assertResponseStatusCodeSame(400);
        $this->em->refresh($target);
        self::assertNull($target->getAvatarFilename());
    }

    public function testNonImageUploadIsIgnored(): void
    {
        $admin = $this->seedUser('admin@avatar-upload-test.local', ['ROLE_ADMIN']);
        $target = $this->seedUser('target@avatar-upload-test.local');
        $this->client->loginUser($admin);

        $path = tempnam(sys_get_temp_dir(), 'avatar-test-');
        file_put_contents($path, 'not an image at all');
        $this->createdFiles[] = $path;

        $this->client->request('POST', $this->avatarUrl($target), [
            '_csrf_token' => $this->csrfToken(),
        ], [
            'avatar' => new UploadedFile($path, 'payload.txt', 'text/plain', test: true),
        ], $this->sameOriginHeaders());

        self::assertResponseRedirects();
        $this->em->refresh($target);
        self::assertNull($target->getAvatarFilename());
    }

    public function testStaffCannotUploadAnAvatar(): void
    {
        $staff = $this->seedUser('staff@avatar-upload-test.local', ['ROLE_SECTION_DOSSIERS']);
        $target = $this->seedUser('target@avatar-upload-test.local');
        $this->client->loginUser($staff);

        $this->client->request('POST', $this->avatarUrl($target), [
            '_csrf_token' => $this->csrfToken(),
        ], [
            'avatar' => $this->makePngUpload(),
        ], $this->sameOriginHeaders());

        self::assertResponseStatusCodeSame(403);
    }

    private function avatarUrl(User $target): string
    {
        return '/fr/'.$this->adminPrefix.'/admin/utilisateurs/'.$target->getUniqueId().'/avatar';
    }

    /**
     * Stateless token id: any value of the expected length passes as long
     * as the same-origin headers do (see config/packages/csrf.yaml).
     */
    private function csrfToken(): string
    {
        return str_repeat('a', 24);
    }

    /**
     * @return array<string, string>
     */
    private function sameOriginHeaders(): array
    {
        return [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_REFERER' => 'http://localhost/fr/'.$this->adminPrefix.'/admin/utilisateurs',
        ];
    }

    private function makePngUpload(): UploadedFile
    {
        $image = imagecreatetruecolor(10, 10);
        $path = tempnam(sys_get_temp_dir(), 'avatar-test-').'.png';
        imagepng($image, $path);
        imagedestroy($image);
        $this->createdFiles[] = $path;

        return new UploadedFile($path, 'avatar.png', 'image/png', test: true);
    }

    /**
     * @param list<string> $roles
     */
    private function seedUser(string $email, array $roles = []): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setFirstName('First')
            ->setLastName('Last')
            ->setRoles($roles)
            ->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)
            ->setVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
