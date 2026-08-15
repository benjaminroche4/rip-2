<?php

declare(strict_types=1);

namespace App\Tests\Dossier;

use App\Dossier\Domain\ContactLanguage;
use App\Dossier\Domain\DossierDocumentStatus;
use App\Dossier\Domain\DossierDocumentType;
use App\Dossier\Domain\DossierPersonRole;
use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierDocument;
use App\Dossier\Entity\DossierDocumentFile;
use App\Dossier\Entity\DossierPerson;
use App\Dossier\Service\DossierDocumentRemover;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class DossierDocumentRemoverTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private DossierDocumentRemover $remover;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.Dossier::class.' d WHERE d.reference LIKE :p')->setParameter('p', 'DS-0904%')->execute();
        $this->remover = self::getContainer()->get(DossierDocumentRemover::class);
    }

    public function testDeletingTheLastFileSendsThePieceBackToRequested(): void
    {
        [$dossier, $document] = $this->persistDossierWithDepositedFile('DS-090401');
        $fileId = (int) $document->getFiles()->first()->getId();
        $path = $this->storagePath($dossier, $document->getFiles()->first()->getStoredName());

        $this->remover->deleteFile($dossier, $fileId);

        self::assertFileDoesNotExist($path);
        $this->em->clear();
        $fresh = $this->em->find(DossierDocument::class, $document->getId());
        self::assertCount(0, $fresh->getFiles());
        self::assertSame(DossierDocumentStatus::Requested, $fresh->getStatus());
        self::assertNull($fresh->getReceivedAt());
    }

    public function testDeletingAnUnknownFileIsANotFound(): void
    {
        [$dossier] = $this->persistDossierWithDepositedFile('DS-090402');

        $this->expectException(NotFoundHttpException::class);

        $this->remover->deleteFile($dossier, 999999999);
    }

    public function testDeletingAPieceRemovesTheRowAndItsFiles(): void
    {
        [$dossier, $document] = $this->persistDossierWithDepositedFile('DS-090403');
        $path = $this->storagePath($dossier, $document->getFiles()->first()->getStoredName());
        $documentId = (int) $document->getId();

        $this->remover->deletePiece($dossier, $document);

        self::assertFileDoesNotExist($path);
        $this->em->clear();
        self::assertNull($this->em->find(DossierDocument::class, $documentId));
    }

    private function storagePath(Dossier $dossier, string $storedName): string
    {
        $storageDir = (string) self::getContainer()->getParameter('dossier_storage_dir');

        return $storageDir.'/'.$dossier->getReference().'/documents/'.$storedName;
    }

    /**
     * @return array{0: Dossier, 1: DossierDocument}
     */
    private function persistDossierWithDepositedFile(string $reference): array
    {
        $tenant = (new DossierPerson())
            ->setRole(DossierPersonRole::TENANT)
            ->setFirstName('Jean')
            ->setLastName('Dupont')
            ->setEmail('remover-tenant@dossier-remover.example')
            ->setLanguage(ContactLanguage::FR)
            ->setPrimaryContact(true);
        $document = (new DossierDocument())
            ->setType(DossierDocumentType::Identity)
            ->setStatus(DossierDocumentStatus::Received)
            ->setRequestedAt(new \DateTimeImmutable('2026-08-01'))
            ->setReceivedAt(new \DateTimeImmutable('2026-08-02'));
        $tenant->addDocument($document);
        $dossier = (new Dossier())
            ->setName('Dupont')
            ->setReference($reference)
            ->setPairingCode(substr($reference, -6))
            ->setCreatedAt(new \DateTimeImmutable())
            ->addPerson($tenant);
        $this->em->persist($dossier);
        $this->em->flush();

        $storageDir = (string) self::getContainer()->getParameter('dossier_storage_dir');
        $dossierDir = $storageDir.'/'.$dossier->getReference().'/documents';
        (new Filesystem())->mkdir($dossierDir);
        $storedName = 'deposited-'.$reference.'.pdf';
        file_put_contents($dossierDir.'/'.$storedName, '%PDF-1.4');
        $document->addFile((new DossierDocumentFile())
            ->setStoredName($storedName)
            ->setOriginalName('original.pdf')
            ->setMimeType('application/pdf')
            ->setSize(8)
            ->setUploadedAt(new \DateTimeImmutable('2026-08-02')));
        $this->em->flush();

        return [$dossier, $document];
    }
}
