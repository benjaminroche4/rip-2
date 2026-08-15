<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Auth\Entity\User;
use App\Dossier\Domain\DossierDocumentStatus;
use App\Dossier\Domain\DossierDocumentType;
use App\Dossier\Domain\DossierPersonRole;
use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierDocument;
use App\Dossier\Entity\DossierPerson;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Discreet alert on the Dossiers section page: open dossiers whose deposit
 * pairing code expired while pieces are still awaited. Nothing to say =
 * nothing rendered; resending the document request re-arms the code.
 */
final class ExpiredPairingAlertTest extends WebTestCase
{
    private const ADMIN_EMAIL = 'expired-pairing-admin@example.com';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $adminPrefix;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();

        $this->adminPrefix = (string) $container->getParameter('admin_path_prefix');
        $this->em = $container->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.Dossier::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email = :email')
            ->setParameter('email', self::ADMIN_EMAIL)
            ->execute();

        $admin = (new User())
            ->setEmail(self::ADMIN_EMAIL)
            ->setFirstName('Alice')
            ->setLastName('Staff')
            ->setRoles(['ROLE_ADMIN'])
            ->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)
            ->setVerified(true);
        $this->em->persist($admin);
        $this->em->flush();
        $this->client->loginUser($admin);
    }

    public function testTheAlertListsOpenDossiersWithAnExpiredCodeAndAwaitedPieces(): void
    {
        $this->persistDossier('DS-000042', 'ABE78L', new \DateTimeImmutable('-91 days'), DossierDocumentStatus::Requested);
        // A refused piece is awaited again: same alert.
        $this->persistDossier('DS-000043', 'QQQ11Q', null, DossierDocumentStatus::Refused);

        $crawler = $this->client->request('GET', $this->dossiersUrl());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="expired-pairing-alert"]');
        $alert = $crawler->filter('[data-testid="expired-pairing-alert"]');
        self::assertStringContainsString('renvoyez la demande de pièces', $alert->text());
        self::assertCount(2, $alert->filter('[data-testid="expired-pairing-alert-link"]'));
        self::assertStringContainsString('DS-000042', $alert->text());
        self::assertStringContainsString('Famille DS-000042', $alert->text());
        // Each entry links to the dossier detail page.
        $hrefs = $alert->filter('[data-testid="expired-pairing-alert-link"]')->each(static fn ($node) => (string) $node->attr('href'));
        self::assertContains('/fr/'.$this->adminPrefix.'/admin/dossiers/DS-000042', $hrefs);
        self::assertContains('/fr/'.$this->adminPrefix.'/admin/dossiers/DS-000043', $hrefs);
    }

    public function testNoAlertWhenTheCodeIsFresh(): void
    {
        $this->persistDossier('DS-000044', 'FRE55H', new \DateTimeImmutable('-1 day'), DossierDocumentStatus::Requested);

        $this->client->request('GET', $this->dossiersUrl());

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-testid="expired-pairing-alert"]');
    }

    public function testNoAlertForAClosedDossier(): void
    {
        $this->persistDossier('DS-000045', 'CLO55D', new \DateTimeImmutable('-91 days'), DossierDocumentStatus::Requested, closed: true);

        $this->client->request('GET', $this->dossiersUrl());

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-testid="expired-pairing-alert"]');
    }

    public function testNoAlertWhenEveryPieceIsValidated(): void
    {
        // An expired code no longer matters once nothing is awaited.
        $this->persistDossier('DS-000046', 'VAL55D', new \DateTimeImmutable('-91 days'), DossierDocumentStatus::Validated);

        $this->client->request('GET', $this->dossiersUrl());

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-testid="expired-pairing-alert"]');
    }

    private function dossiersUrl(): string
    {
        return '/fr/'.$this->adminPrefix.'/admin/dossiers';
    }

    private function persistDossier(
        string $reference,
        string $pairingCode,
        ?\DateTimeImmutable $pairingCodeSentAt,
        DossierDocumentStatus $documentStatus,
        bool $closed = false,
    ): void {
        $tenant = (new DossierPerson())
            ->setRole(DossierPersonRole::TENANT)
            ->setFirstName('Jean')
            ->setLastName('Dupont')
            ->setEmail('tenant-'.strtolower($reference).'@example.com')
            ->setPrimaryContact(true);
        $tenant->addDocument((new DossierDocument())
            ->setType(DossierDocumentType::Identity)
            ->setStatus($documentStatus)
            ->setRequestedAt(new \DateTimeImmutable('-100 days')));

        $dossier = (new Dossier())
            ->setName('Famille '.$reference)
            ->setReference($reference)
            ->setPairingCode($pairingCode)
            ->setPairingCodeSentAt($pairingCodeSentAt)
            ->setCreatedAt(new \DateTimeImmutable('-100 days'))
            ->setClosedAt($closed ? new \DateTimeImmutable('-1 day') : null)
            ->addPerson($tenant);

        $this->em->persist($dossier);
        $this->em->flush();
    }
}
