<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\Entity\Document;
use App\Auth\Entity\ResetPasswordRequest;
use App\Auth\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Verrouille le contrat CSRF des LiveActions de mutation. Les 3 FormType
 * admin ont `csrf_protection: false` car Symfony UX LiveComponent gère
 * son propre token via `data-live-csrf-value`. Si un upgrade futur du
 * bundle change ce comportement, ces tests le détecteront avant la prod.
 *
 * Stratégie : on POSTe directement sur l'endpoint LiveComponent
 * (`/_components/{Name}/{action}`) authentifié comme admin mais sans
 * fournir le token CSRF Live attendu. Le middleware doit refuser la
 * mutation (4xx).
 */
final class CsrfLiveActionTest extends WebTestCase
{
    private const ADMIN_EMAIL = 'csrf-live-admin@example.com';
    private const PASSWORD = 'password';

    private KernelBrowser $client;
    private string $adminPrefix;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();

        $this->adminPrefix = (string) $container->getParameter('admin_path_prefix');

        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine.orm.entity_manager');
        $em->createQuery('DELETE FROM '.Document::class)->execute();
        $em->createQuery('DELETE FROM '.ResetPasswordRequest::class)->execute();
        $em->createQuery('DELETE FROM '.User::class.' u WHERE u.email = :e')
            ->setParameter('e', self::ADMIN_EMAIL)
            ->execute();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get('security.user_password_hasher');

        $admin = (new User())
            ->setEmail(self::ADMIN_EMAIL)
            ->setFirstName('CSRF')
            ->setLastName('Admin')
            ->setRoles(['ROLE_ADMIN'])
            ->setCreatedAt(new \DateTimeImmutable());
        $admin->setPassword($hasher->hashPassword($admin, self::PASSWORD));

        $em->persist($admin);
        $em->flush();

        $this->client->loginUser($admin);
    }

    public function testUserListMoreActionRejectsRequestWithoutCsrfToken(): void
    {
        $this->postLiveAction('Admin:UserList', 'more', [
            'props' => ['page' => 1, 'adminPrefix' => $this->adminPrefix],
        ]);

        self::assertGreaterThanOrEqual(400, $this->client->getResponse()->getStatusCode());
        self::assertLessThan(500, $this->client->getResponse()->getStatusCode());
    }

    public function testPaymentListMoreActionRejectsRequestWithoutCsrfToken(): void
    {
        $this->postLiveAction('Admin:PaymentList', 'more', [
            'props' => ['page' => 1, 'currencySymbol' => '€'],
        ]);

        self::assertGreaterThanOrEqual(400, $this->client->getResponse()->getStatusCode());
        self::assertLessThan(500, $this->client->getResponse()->getStatusCode());
    }

    public function testDocumentListDeleteActionRejectsRequestWithoutCsrfToken(): void
    {
        // Seed a doc so the action would have something to delete if it ran.
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $doc = (new Document())
            ->setNameFr('Test FR')
            ->setNameEn('Test EN')
            ->setSlug('csrf-test-doc')
            ->setCategory(\App\Admin\Domain\DocumentCategory::OTHER)
            ->setCreatedAt(new \DateTimeImmutable());
        $em->persist($doc);
        $em->flush();
        $id = $doc->getId();

        $this->postLiveAction('Admin:DocumentList', 'delete', [
            'props' => ['page' => 1],
            'args' => ['id' => $id],
        ]);

        self::assertGreaterThanOrEqual(400, $this->client->getResponse()->getStatusCode());

        // Side-effect check: the doc still exists — the mutation was blocked.
        $em->clear();
        self::assertNotNull(
            $em->getRepository(Document::class)->find($id),
            'Without a valid CSRF token, the document MUST NOT have been deleted.',
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function postLiveAction(string $component, string $action, array $payload): void
    {
        $url = sprintf('/fr/_components/%s/%s', $component, $action);

        // Live components expect a JSON body with `data` (props/args). We
        // omit `X-CSRF-TOKEN` and the live-csrf form field on purpose so
        // the middleware should reject the request.
        $this->client->request(
            'POST',
            $url,
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
                'HTTP_ACCEPT' => 'application/vnd.live-component+html',
            ],
            json_encode(['data' => $payload], JSON_THROW_ON_ERROR),
        );
    }
}
