<?php

declare(strict_types=1);

namespace App\Tests\Dossier;

use App\Dossier\Domain\ContactLanguage;
use App\Dossier\Domain\DossierDocumentStatus;
use App\Dossier\Domain\DossierDocumentType;
use App\Dossier\Domain\DossierPersonRole;
use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierPerson;
use App\Dossier\Service\DossierDocumentRequester;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;

final class DossierDocumentRequesterTest extends KernelTestCase
{
    use MailerAssertionsTrait;

    private EntityManagerInterface $em;
    private DossierDocumentRequester $requester;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.Dossier::class.' d WHERE d.reference LIKE :p')->setParameter('p', 'DS-0902%')->execute();
        $this->requester = self::getContainer()->get(DossierDocumentRequester::class);
    }

    public function testItCreatesRequestedRowsAndSendsTheEmail(): void
    {
        $dossier = $this->persistDossier('DS-090201');
        $tenant = $dossier->getPersons()->first();

        $this->requester->request($dossier, $tenant, $tenant, [DossierDocumentType::Identity, DossierDocumentType::Payslips]);

        self::assertEmailCount(1);
        $this->em->clear();
        $fresh = $this->em->find(Dossier::class, $dossier->getId());
        $documents = $fresh->getPersons()->first()->getDocuments();
        self::assertCount(2, $documents);
        self::assertSame(DossierDocumentStatus::Requested, $documents->first()->getStatus());
        self::assertNotNull($documents->first()->getRequestedAt());
        // The email embeds the pairing code: the send re-arms its expiry.
        self::assertNotNull($fresh->getPairingCodeSentAt());
    }

    public function testItResendsToEveryRecipientAndReportsWhatLeft(): void
    {
        $dossier = $this->persistDossier('DS-090202');
        $tenant = $dossier->getPersons()->first();

        $outcome = $this->requester->resend($dossier, $tenant, [$tenant], [DossierDocumentType::Identity]);

        self::assertSame(['sent' => ['requester-tenant@dossier-requester.example'], 'failed' => false], $outcome);
        self::assertEmailCount(1);
        // The reminder embeds the pairing code: the send re-arms its expiry.
        $this->em->clear();
        self::assertNotNull($this->em->find(Dossier::class, $dossier->getId())->getPairingCodeSentAt());
    }

    private function persistDossier(string $reference): Dossier
    {
        $tenant = (new DossierPerson())
            ->setRole(DossierPersonRole::TENANT)
            ->setFirstName('Jean')
            ->setLastName('Dupont')
            ->setEmail('requester-tenant@dossier-requester.example')
            ->setLanguage(ContactLanguage::FR)
            ->setPrimaryContact(true);
        $dossier = (new Dossier())
            ->setName('Dupont')
            ->setReference($reference)
            ->setPairingCode(substr($reference, -6))
            ->setCreatedAt(new \DateTimeImmutable())
            ->addPerson($tenant);

        $this->em->persist($dossier);
        $this->em->flush();

        return $dossier;
    }
}
