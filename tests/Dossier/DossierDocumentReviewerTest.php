<?php

declare(strict_types=1);

namespace App\Tests\Dossier;

use App\Dossier\Domain\ContactLanguage;
use App\Dossier\Domain\DossierDocumentStatus;
use App\Dossier\Domain\DossierDocumentType;
use App\Dossier\Domain\DossierPersonRole;
use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierDocument;
use App\Dossier\Entity\DossierPerson;
use App\Dossier\Service\DossierDocumentReviewer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;

final class DossierDocumentReviewerTest extends KernelTestCase
{
    use MailerAssertionsTrait;

    private EntityManagerInterface $em;
    private DossierDocumentReviewer $reviewer;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.Dossier::class.' d WHERE d.reference LIKE :p')->setParameter('p', 'DS-0903%')->execute();
        $this->reviewer = self::getContainer()->get(DossierDocumentReviewer::class);
    }

    public function testItStampsReceivedAtWhenThePieceIsDeposited(): void
    {
        [$dossier, $document] = $this->persistDossierWithDocument('DS-090301');

        $this->reviewer->applyStatus($dossier, $document, DossierDocumentStatus::Received);

        $this->em->clear();
        $fresh = $this->em->find(DossierDocument::class, $document->getId());
        self::assertSame(DossierDocumentStatus::Received, $fresh->getStatus());
        self::assertNotNull($fresh->getReceivedAt());
    }

    public function testItClearsReceivedAtWhenThePieceGoesBackToRequested(): void
    {
        [$dossier, $document] = $this->persistDossierWithDocument('DS-090302');
        $document->setStatus(DossierDocumentStatus::Received)->setReceivedAt(new \DateTimeImmutable('2026-08-01'));
        $this->em->flush();

        $this->reviewer->applyStatus($dossier, $document, DossierDocumentStatus::Requested);

        $this->em->clear();
        $fresh = $this->em->find(DossierDocument::class, $document->getId());
        self::assertSame(DossierDocumentStatus::Requested, $fresh->getStatus());
        self::assertNull($fresh->getReceivedAt());
    }

    public function testItRefusesWithATruncatedReasonAndNotifiesTheTenant(): void
    {
        [$dossier, $document] = $this->persistDossierWithDocument('DS-090303');

        $this->reviewer->refuse($dossier, $document, '  '.str_repeat('r', 600).'  ');

        self::assertEmailCount(1);
        $this->em->clear();
        $fresh = $this->em->find(DossierDocument::class, $document->getId());
        self::assertSame(DossierDocumentStatus::Refused, $fresh->getStatus());
        self::assertSame(500, mb_strlen((string) $fresh->getRefusalReason()));
        // The refusal email embeds the pairing code: the send re-arms its expiry.
        self::assertNotNull($this->em->find(Dossier::class, $dossier->getId())->getPairingCodeSentAt());
    }

    public function testItSavesAndClearsThePublicDescription(): void
    {
        [$dossier, $document] = $this->persistDossierWithDocument('DS-090304');

        $this->reviewer->updateDescription($dossier, $document, '  Passport, both sides  ');
        $this->em->clear();
        $fresh = $this->em->find(DossierDocument::class, $document->getId());
        self::assertSame('Passport, both sides', $fresh->getDescription());

        $freshDossier = $this->em->find(Dossier::class, $dossier->getId());
        $this->reviewer->updateDescription($freshDossier, $fresh, '   ');
        $this->em->clear();
        self::assertNull($this->em->find(DossierDocument::class, $document->getId())->getDescription());
    }

    /**
     * @return array{0: Dossier, 1: DossierDocument}
     */
    private function persistDossierWithDocument(string $reference): array
    {
        $tenant = (new DossierPerson())
            ->setRole(DossierPersonRole::TENANT)
            ->setFirstName('Jean')
            ->setLastName('Dupont')
            ->setEmail('reviewer-tenant@dossier-reviewer.example')
            ->setLanguage(ContactLanguage::FR)
            ->setPrimaryContact(true);
        $document = (new DossierDocument())
            ->setType(DossierDocumentType::Identity)
            ->setStatus(DossierDocumentStatus::Requested)
            ->setRequestedAt(new \DateTimeImmutable('2026-08-01'));
        $tenant->addDocument($document);
        $dossier = (new Dossier())
            ->setName('Dupont')
            ->setReference($reference)
            ->setPairingCode(substr($reference, -6))
            ->setCreatedAt(new \DateTimeImmutable())
            ->addPerson($tenant);

        $this->em->persist($dossier);
        $this->em->flush();

        return [$dossier, $document];
    }
}
