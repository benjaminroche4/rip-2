<?php

declare(strict_types=1);

namespace App\Tests\Dossier;

use App\Dossier\Domain\ContactLanguage;
use App\Dossier\Domain\DossierDocumentStatus;
use App\Dossier\Domain\DossierDocumentType;
use App\Dossier\Domain\DossierPersonRole;
use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierDocument;
use App\Dossier\Entity\DossierEvent;
use App\Dossier\Entity\DossierPerson;
use App\Dossier\Repository\DossierEventRepository;
use App\Dossier\Service\DossierDocumentReviewer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;

final class DossierDocumentCompletionTest extends KernelTestCase
{
    use MailerAssertionsTrait;

    private EntityManagerInterface $em;
    private DossierDocumentReviewer $reviewer;
    private DossierEventRepository $events;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.Dossier::class.' d WHERE d.reference LIKE :p')->setParameter('p', 'DS-0815%')->execute();
        $this->reviewer = self::getContainer()->get(DossierDocumentReviewer::class);
        $this->events = self::getContainer()->get(DossierEventRepository::class);
    }

    public function testItSendsTheCompletionEmailWhenTheLastPieceIsValidated(): void
    {
        [$dossier, $documents] = $this->persistDossier('DS-081501', 2);
        $documents[0]->setStatus(DossierDocumentStatus::Validated);
        $this->em->flush();

        $this->reviewer->applyStatus($dossier, $documents[1], DossierDocumentStatus::Validated);

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertEmailAddressContains($email, 'to', 'completion-tenant@dossier-completion.example');
        self::assertEmailHtmlBodyContains($email, 'Votre dossier est complet');
        self::assertNotNull($this->events->findLatestOfKind((int) $dossier->getId(), 'documents_completed'));
        // The completion email embeds the pairing code: the send re-arms its expiry.
        $this->em->clear();
        self::assertNotNull($this->em->find(Dossier::class, $dossier->getId())->getPairingCodeSentAt());
    }

    public function testItStaysSilentWhileOtherPiecesAreStillPending(): void
    {
        [$dossier, $documents] = $this->persistDossier('DS-081502', 2);

        $this->reviewer->applyStatus($dossier, $documents[0], DossierDocumentStatus::Validated);

        self::assertEmailCount(0);
        self::assertNull($this->events->findLatestOfKind((int) $dossier->getId(), 'documents_completed'));
        $this->em->clear();
        self::assertNull($this->em->find(Dossier::class, $dossier->getId())->getPairingCodeSentAt());
    }

    public function testItDoesNotResendAfterAStatusRoundTripOnTheSamePiece(): void
    {
        [$dossier, $documents] = $this->persistDossier('DS-081503', 1);

        $this->reviewer->applyStatus($dossier, $documents[0], DossierDocumentStatus::Validated);
        self::assertEmailCount(1);

        $this->reviewer->applyStatus($dossier, $documents[0], DossierDocumentStatus::Received);
        $this->reviewer->applyStatus($dossier, $documents[0], DossierDocumentStatus::Validated);

        self::assertEmailCount(1);
    }

    public function testANewRequestCycleReArmsTheCompletionEmail(): void
    {
        [$dossier, $documents] = $this->persistDossier('DS-081504', 1);

        $this->reviewer->applyStatus($dossier, $documents[0], DossierDocumentStatus::Validated);
        self::assertEmailCount(1);

        // A new piece is asked (documents_requested logged after the last
        // documents_completed): its validation re-completes the file.
        $extra = (new DossierDocument())
            ->setType(DossierDocumentType::Payslips)
            ->setStatus(DossierDocumentStatus::Requested)
            ->setRequestedAt(new \DateTimeImmutable());
        $dossier->getPersons()->first()->addDocument($extra);
        $this->em->persist((new DossierEvent())
            ->setDossier($dossier)
            ->setKind('documents_requested')
            ->setPayload([])
            ->setCreatedAt(new \DateTimeImmutable('+1 minute')));
        $this->em->flush();

        $this->reviewer->applyStatus($dossier, $extra, DossierDocumentStatus::Validated);

        self::assertEmailCount(2);
    }

    public function testItLogsNothingWhenNoRecipientIsReachable(): void
    {
        [$dossier, $documents] = $this->persistDossier('DS-081505', 1, email: '');

        $this->reviewer->applyStatus($dossier, $documents[0], DossierDocumentStatus::Validated);

        self::assertEmailCount(0);
        self::assertNull($this->events->findLatestOfKind((int) $dossier->getId(), 'documents_completed'));
        $this->em->clear();
        $fresh = $this->em->find(Dossier::class, $dossier->getId());
        self::assertNull($fresh->getPairingCodeSentAt());
        // The validation itself still went through.
        self::assertFalse($fresh->hasPendingDocuments());
    }

    /**
     * @return array{0: Dossier, 1: list<DossierDocument>}
     */
    private function persistDossier(string $reference, int $documentCount, string $email = 'completion-tenant@dossier-completion.example'): array
    {
        $tenant = (new DossierPerson())
            ->setRole(DossierPersonRole::TENANT)
            ->setFirstName('Jean')
            ->setLastName('Dupont')
            ->setEmail($email)
            ->setLanguage(ContactLanguage::FR)
            ->setPrimaryContact(true);
        $types = DossierDocumentType::cases();
        $documents = [];
        for ($i = 0; $i < $documentCount; ++$i) {
            $documents[] = $document = (new DossierDocument())
                ->setType($types[$i])
                ->setStatus(DossierDocumentStatus::Requested)
                ->setRequestedAt(new \DateTimeImmutable('2026-08-01'));
            $tenant->addDocument($document);
        }
        $dossier = (new Dossier())
            ->setName('Dupont')
            ->setReference($reference)
            ->setPairingCode(substr($reference, -6))
            ->setCreatedAt(new \DateTimeImmutable())
            ->addPerson($tenant);

        $this->em->persist($dossier);
        $this->em->flush();

        return [$dossier, $documents];
    }
}
