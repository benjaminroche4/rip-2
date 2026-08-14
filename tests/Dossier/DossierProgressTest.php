<?php

declare(strict_types=1);

namespace App\Tests\Dossier;

use App\Dossier\Domain\ContactLanguage;
use App\Dossier\Domain\DossierDocumentStatus;
use App\Dossier\Domain\DossierDocumentType;
use App\Dossier\Domain\DossierPersonRole;
use App\Dossier\Domain\DossierStatus;
use App\Dossier\Domain\DossierStep;
use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierDocument;
use App\Dossier\Entity\DossierPerson;
use App\Dossier\Entity\DossierSearch;
use App\Dossier\Service\DossierProgressCalculator;
use App\Dossier\Service\DossierStatusAdvancer;
use App\Visit\Entity\Visit;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Parcours en étapes de la fiche dossier : chaque étape n'ouvre la suivante
 * qu'une fois validée, et le statut du dossier suit sans intervention.
 */
final class DossierProgressTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private DossierProgressCalculator $calculator;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->calculator = self::getContainer()->get(DossierProgressCalculator::class);
        $this->em->createQuery('DELETE FROM '.Visit::class)->execute();
        $this->em->createQuery('DELETE FROM '.Dossier::class)->execute();
    }

    public function testStepsUnlockOneAfterTheOther(): void
    {
        $dossier = $this->persistDossier();

        // Un dossier sans locataire ne franchit même pas la première étape.
        $progress = $this->calculator->forDossier($dossier);
        self::assertFalse($progress->isValidated(DossierStep::Persons));
        self::assertTrue($progress->isUnlocked(DossierStep::Persons), 'La première étape est toujours ouverte.');
        self::assertFalse($progress->isUnlocked(DossierStep::Search));
        self::assertSame(DossierStep::Persons, $progress->blockedBy(DossierStep::Search));
        self::assertSame(DossierStep::Persons, $progress->currentStep());

        $this->addPrimaryTenant($dossier);
        $progress = $this->calculator->forDossier($dossier);
        self::assertTrue($progress->isValidated(DossierStep::Persons));
        self::assertTrue($progress->isUnlocked(DossierStep::Search));
        self::assertFalse($progress->isUnlocked(DossierStep::File));

        $dossier->setSearch($this->completeSearch());
        $this->em->flush();
        $progress = $this->calculator->forDossier($dossier);
        self::assertTrue($progress->isUnlocked(DossierStep::File));
        self::assertFalse($progress->isUnlocked(DossierStep::Visit));

        // Une pièce demandée mais pas encore validée ne suffit pas.
        $document = $this->requestDocument($dossier);
        self::assertFalse($this->calculator->forDossier($dossier)->isUnlocked(DossierStep::Visit));

        $document->setStatus(DossierDocumentStatus::Validated);
        $this->em->flush();
        $progress = $this->calculator->forDossier($dossier);
        self::assertTrue($progress->isUnlocked(DossierStep::Visit));
        self::assertFalse($progress->isUnlocked(DossierStep::Payment));

        // Une visite planifiée ne vaut pas une visite réalisée.
        $visit = $this->persistVisit($dossier, \App\Visit\Domain\VisitStatus::Planned);
        self::assertFalse($this->calculator->forDossier($dossier)->isUnlocked(DossierStep::Payment));

        $visit->setStatus(\App\Visit\Domain\VisitStatus::Done);
        $this->em->flush();
        $progress = $this->calculator->forDossier($dossier);
        self::assertTrue($progress->isUnlocked(DossierStep::Payment));
        self::assertSame(DossierStep::Payment, $progress->currentStep(), 'Le paiement est terminal, jamais "validé".');
    }

    public function testAFileWithoutAnyRequestedPieceIsNotValidated(): void
    {
        $dossier = $this->persistDossier();
        $this->addPrimaryTenant($dossier);
        $dossier->setSearch($this->completeSearch());
        $this->em->flush();

        // hasPendingDocuments() seul répondrait "rien en attente" : c'est
        // justement le piège que l'étape Dossier ne doit pas tomber dedans.
        self::assertFalse($dossier->hasPendingDocuments());
        self::assertFalse($this->calculator->forDossier($dossier)->isValidated(DossierStep::File));
    }

    public function testStatusFollowsTheValidatedStepsWithoutEverGoingBackwards(): void
    {
        $advancer = self::getContainer()->get(DossierStatusAdvancer::class);
        $dossier = $this->persistDossier();
        self::assertSame(DossierStatus::New, $dossier->getStatus());

        // Recherche complète sans locataire principal : l'étape 1 manque,
        // donc rien n'avance.
        $dossier->setSearch($this->completeSearch());
        $this->em->flush();
        self::assertSame(DossierStatus::New, $advancer->advance($dossier));

        $this->addPrimaryTenant($dossier);
        self::assertSame(DossierStatus::Searching, $advancer->advance($dossier));

        // Statut avancé à la main : l'automatisme ne le redescend jamais.
        $dossier->setStatus(DossierStatus::PropertyFound);
        $this->em->flush();
        self::assertSame(DossierStatus::PropertyFound, $advancer->advance($dossier));

        // Un dossier clôturé n'est plus touché.
        $dossier->setStatus(DossierStatus::New);
        $dossier->setClosedAt(new \DateTimeImmutable());
        $this->em->flush();
        self::assertSame(DossierStatus::New, $advancer->advance($dossier));
    }

    private function persistDossier(): Dossier
    {
        $dossier = (new Dossier())
            ->setName('Parcours')
            ->setReference('DS-'.random_int(100000, 999999))
            ->setPairingCode(substr(strtoupper(bin2hex(random_bytes(4))), 0, 6))
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($dossier);
        $this->em->flush();

        return $dossier;
    }

    private function addPrimaryTenant(Dossier $dossier): DossierPerson
    {
        $tenant = (new DossierPerson())
            ->setRole(DossierPersonRole::TENANT)
            ->setFirstName('Jean')
            ->setLastName('Dupont')
            ->setEmail('jean@progress-test.example')
            ->setLanguage(ContactLanguage::FR)
            ->setPrimaryContact(true);
        $dossier->addPerson($tenant);
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function completeSearch(): DossierSearch
    {
        return (new DossierSearch())
            ->setBudget(2500)
            ->setAreas('11e, 12e')
            ->setMoveInAt(new \DateTimeImmutable('+3 months'))
            ->setPropertyType('t2')
            ->setStayDuration('long')
            ->setFurnishing('furnished')
            ->setGuarantorType('physical');
    }

    private function requestDocument(Dossier $dossier): DossierDocument
    {
        /** @var DossierPerson $tenant */
        $tenant = $dossier->getPersons()->first();
        $document = (new DossierDocument())
            ->setType(DossierDocumentType::Identity)
            ->setStatus(DossierDocumentStatus::Requested)
            ->setRequestedAt(new \DateTimeImmutable());
        $tenant->addDocument($document);
        $this->em->persist($document);
        $this->em->flush();

        return $document;
    }

    private function persistVisit(Dossier $dossier, \App\Visit\Domain\VisitStatus $status): Visit
    {
        $visit = (new Visit())
            ->setDossier($dossier)
            ->setAddress('12 rue de la Roquette, 75011 Paris')
            ->setScheduledAt(new \DateTimeImmutable('-2 days 10:00'))
            ->setStatus($status)
            ->setReference('VS-'.random_int(100000, 999999))
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($visit);
        $this->em->flush();

        return $visit;
    }
}
