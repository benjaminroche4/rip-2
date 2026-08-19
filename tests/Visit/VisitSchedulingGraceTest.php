<?php

declare(strict_types=1);

namespace App\Tests\Visit;

use App\Dossier\Entity\Dossier;
use App\Visit\Entity\Visit;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Grâce de 10 minutes du refus du passé à la création (groupe visit_create) :
 * réserver le créneau de la minute en cours doit passer, la contrainte de
 * l'entité étant alignée sur celle du formulaire (VisitType). Sans le fix
 * (GreaterThanOrEqual('now')), un scheduledAt posé "maintenant" est déjà
 * dans le passé au moment de la validation et la réservation échoue.
 */
final class VisitSchedulingGraceTest extends KernelTestCase
{
    public function testBookingTheCurrentMinuteSlotPassesValidation(): void
    {
        self::bootKernel();
        /** @var ValidatorInterface $validator */
        $validator = self::getContainer()->get(ValidatorInterface::class);

        // Créneau "tout de suite" : l'heure courante, seconde près, est
        // forcément antérieure au 'now' évalué par la contrainte.
        $violations = $validator->validate(
            $this->makeVisit(new \DateTimeImmutable('now')),
            groups: ['Default', 'visit_create'],
        );

        self::assertCount(0, $violations, (string) $violations);
    }

    public function testAVisitBeyondTheGraceIsStillRefusedAtCreation(): void
    {
        self::bootKernel();
        /** @var ValidatorInterface $validator */
        $validator = self::getContainer()->get(ValidatorInterface::class);

        $violations = $validator->validate(
            $this->makeVisit(new \DateTimeImmutable('-11 minutes')),
            groups: ['Default', 'visit_create'],
        );

        self::assertGreaterThan(0, $violations->count(), 'A slot beyond the 10 minute grace is refused.');
    }

    private function makeVisit(\DateTimeImmutable $scheduledAt): Visit
    {
        // Entité non persistée : la validation n'a pas besoin de la base.
        $dossier = (new Dossier())
            ->setName('Famille Grace')
            ->setReference('DS-999999')
            ->setPairingCode('GRACE0')
            ->setCreatedAt(new \DateTimeImmutable());

        return (new Visit())
            ->setReference('VS-999999')
            ->setDossier($dossier)
            ->setScheduledAt($scheduledAt)
            ->setAddress('12 rue de la Roquette, 75011 Paris')
            ->setCreatedAt(new \DateTimeImmutable());
    }
}
