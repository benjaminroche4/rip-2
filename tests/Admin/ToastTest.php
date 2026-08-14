<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Auth\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Success toasts of the back-office: the stack ships on every admin page and
 * a "success" flash left by a redirecting action (new lead, new dossier) is
 * rendered as a toast on the page we land on.
 */
final class ToastTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $adminPrefix;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->adminPrefix = (string) $container->getParameter('admin_path_prefix');
        $this->em = $container->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@toast-test.local')->execute();
    }

    public function testAdminPagesCarryTheToastStack(): void
    {
        $this->login();

        $this->client->request('GET', $this->profileUrl());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="toasts"]');
        // The markup lives in a template the Stimulus controller clones.
        self::assertSelectorExists('[data-toast-target="template"]');
        // Nothing to announce yet.
        self::assertSelectorNotExists('[data-toast-target="list"] [data-toast]');
    }

    public function testSuccessFlashIsRenderedAsAToast(): void
    {
        $this->login();
        $this->seedSuccessFlash('admin.dossiers.create.success');

        $this->client->request('GET', $this->profileUrl());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-toast-target="list"] [data-toast]', 'Dossier créé.');

        // Flashes are consumed: the toast does not come back on the next page.
        $this->client->request('GET', $this->profileUrl());
        self::assertSelectorNotExists('[data-toast-target="list"] [data-toast]');
    }

    /**
     * Leaves a flash in the very session the client carries, the way a
     * redirecting LiveComponent action does.
     */
    private function seedSuccessFlash(string $messageKey): void
    {
        // A first request opens the session the flash must land in.
        $this->client->request('GET', $this->profileUrl());

        $session = $this->client->getRequest()->getSession();
        $session->getFlashBag()->add('success', $messageKey);
        $session->save();
    }

    private function login(): void
    {
        $user = (new User())
            ->setEmail('staff@toast-test.local')
            ->setFirstName('Sam')->setLastName('Staff')
            ->setRoles(['ROLE_SECTION_DOSSIERS'])->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);
    }

    private function profileUrl(): string
    {
        return '/fr/'.$this->adminPrefix.'/admin/mon-profil';
    }
}
