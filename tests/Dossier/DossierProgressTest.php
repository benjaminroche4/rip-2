<?php

declare(strict_types=1);

namespace App\Tests\Dossier;

use App\Dossier\Domain\DossierStatus;
use App\Dossier\Domain\DossierStep;
use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierEvent;
use App\Dossier\Service\DossierProgressCalculator;
use App\Dossier\Service\DossierStatusAdvancer;
use App\Dossier\Service\DossierStepValidator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Parcours en étapes de la fiche dossier : la validation est un geste
 * explicite du staff (bouton "Valider" en bas de chaque card), qui
 * déverrouille l'étape suivante et fait avancer le statut du dossier.
 */
final class DossierProgressTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private DossierProgressCalculator $calculator;
    private DossierStepValidator $validator;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->calculator = self::getContainer()->get(DossierProgressCalculator::class);
        $this->validator = self::getContainer()->get(DossierStepValidator::class);
        $this->em->createQuery('DELETE FROM '.Dossier::class)->execute();
    }

    public function testValidatingAStepUnlocksTheNextOne(): void
    {
        $dossier = $this->persistDossier();

        $progress = $this->calculator->forDossier($dossier);
        self::assertTrue($progress->isUnlocked(DossierStep::Persons), 'La première étape est toujours ouverte.');
        self::assertFalse($progress->isUnlocked(DossierStep::Search));
        self::assertSame(DossierStep::Persons, $progress->blockedBy(DossierStep::Search));
        self::assertSame(DossierStep::Persons, $progress->currentStep());

        self::assertTrue($this->validator->validate($dossier, DossierStep::Persons));
        $progress = $this->calculator->forDossier($dossier);
        self::assertTrue($progress->isValidated(DossierStep::Persons));
        self::assertTrue($progress->isUnlocked(DossierStep::Search));
        self::assertFalse($progress->isUnlocked(DossierStep::File));

        self::assertTrue($this->validator->validate($dossier, DossierStep::Search));
        self::assertTrue($this->validator->validate($dossier, DossierStep::File));
        self::assertTrue($this->validator->validate($dossier, DossierStep::Visit));
        $progress = $this->calculator->forDossier($dossier);
        self::assertTrue($progress->isUnlocked(DossierStep::Payment));
        self::assertSame(DossierStep::Payment, $progress->currentStep(), 'Le paiement est terminal, jamais "validé".');
    }

    public function testAStepCannotBeValidatedOutOfOrderNorTwice(): void
    {
        $dossier = $this->persistDossier();

        self::assertFalse($this->validator->validate($dossier, DossierStep::Search), 'Personnes pas encore validée → refus.');
        self::assertFalse($this->calculator->forDossier($dossier)->isValidated(DossierStep::Search));

        self::assertTrue($this->validator->validate($dossier, DossierStep::Persons));
        self::assertFalse($this->validator->validate($dossier, DossierStep::Persons), 'Déjà validée → no-op.');
    }

    public function testOnlyTheLastValidatedStepCanBeReopened(): void
    {
        $dossier = $this->persistDossier();
        $this->validator->validate($dossier, DossierStep::Persons);
        $this->validator->validate($dossier, DossierStep::Search);

        self::assertFalse($this->validator->reopen($dossier, DossierStep::Persons), 'Recherche validée derrière → refus.');
        self::assertTrue($this->validator->reopen($dossier, DossierStep::Search));

        $progress = $this->calculator->forDossier($dossier);
        self::assertFalse($progress->isValidated(DossierStep::Search));
        self::assertFalse($progress->isUnlocked(DossierStep::File));
        self::assertTrue($this->validator->reopen($dossier, DossierStep::Persons), 'Devenue la dernière validée → rouvrable.');

        self::assertFalse($this->validator->reopen($dossier, DossierStep::Persons), 'Plus validée → no-op.');
    }

    public function testValidationIsLoggedOnTheFollowUpThread(): void
    {
        $dossier = $this->persistDossier();
        $this->validator->validate($dossier, DossierStep::Persons);
        $this->validator->reopen($dossier, DossierStep::Persons);

        $kinds = array_map(
            static fn (DossierEvent $event): string => $event->getKind(),
            $this->em->getRepository(DossierEvent::class)->findBy(['dossier' => $dossier]),
        );
        self::assertContains('step_validated', $kinds);
        self::assertContains('step_reopened', $kinds);
    }

    public function testStatusNamesThePendingStepInBothDirections(): void
    {
        $advancer = self::getContainer()->get(DossierStatusAdvancer::class);
        $dossier = $this->persistDossier();
        self::assertSame(DossierStatus::Persons, $dossier->getStatus());

        $this->validator->validate($dossier, DossierStep::Persons);
        self::assertSame(DossierStatus::Search, $dossier->getStatus(), 'Personnes validée : la Recherche est en attente.');

        $this->validator->validate($dossier, DossierStep::Search);
        self::assertSame(DossierStatus::File, $dossier->getStatus());

        $this->validator->validate($dossier, DossierStep::File);
        self::assertSame(DossierStatus::Visit, $dossier->getStatus());

        // Visite validée = bien trouvé : finalisation, même avec le
        // paiement encore ouvert.
        $this->validator->validate($dossier, DossierStep::Visit);
        self::assertSame(DossierStatus::Finalization, $dossier->getStatus());

        // Le statut n'a plus de sélecteur manuel : rouvrir une étape le
        // réaligne aussi vers le bas.
        $this->validator->reopen($dossier, DossierStep::Visit);
        self::assertSame(DossierStatus::Visit, $dossier->getStatus());

        // Un dossier clôturé n'est plus touché par l'automatisme.
        $dossier->setStatus(DossierStatus::Persons);
        $dossier->setClosedAt(new \DateTimeImmutable());
        $this->em->flush();
        self::assertSame(DossierStatus::Persons, $advancer->advance($dossier));
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
}
