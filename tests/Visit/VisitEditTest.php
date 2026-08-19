<?php

declare(strict_types=1);

namespace App\Tests\Visit;

use App\Auth\Entity\User;
use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierSearch;
use App\Visit\Entity\Visit;
use App\Visit\Service\AddressGeocoder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * Edit mode of Visit:VisitForm (the "Modifier" page): the same form mounted
 * on the existing visit updates it in place. No new reference, no dossier
 * event, no client email; the dossier is locked; the guards exclude the
 * visit itself so its own slot can move.
 */
final class VisitEditTest extends KernelTestCase
{
    use InteractsWithTwigComponents;
    use MailerAssertionsTrait;

    private const PREFIX = 'test_admin_prefix_1234567890abcdef';

    private EntityManagerInterface $em;
    private Dossier $dossier;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.Visit::class)->execute();
        $this->em->createQuery('DELETE FROM '.Dossier::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@visit-edit-test.local')->execute();

        $this->dossier = $this->persistDossier('Famille Martin');
        $this->loginAsAdmin();
    }

    public function testSaveUpdatesTheVisitInPlaceWithoutSideEffects(): void
    {
        // Un contact joignable prouve qu'aucun email ne part à l'update.
        $person = (new \App\Dossier\Entity\DossierPerson())
            ->setRole(\App\Dossier\Domain\DossierPersonRole::TENANT)
            ->setFirstName('Jean')->setLastName('Martin')
            ->setEmail('jean@visit-edit-test.local')
            ->setPrimaryContact(true);
        $this->dossier->addPerson($person);
        $this->em->flush();

        $visit = $this->persistVisit();
        $reference = (string) $visit->getReference();
        $newSlot = (new \DateTimeImmutable('+4 days'))->setTime(15, 0);

        $component = $this->mountEdit($visit);
        $component->formValues = $this->values($visit, scheduledAt: $newSlot->format('Y-m-d\TH:i'), note: 'Code 4812');

        $response = $component->create($this->em, $this->nullGeocoder());

        // Redirection vers la fiche, pas vers la liste.
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertStringContainsString('/'.$reference, (string) $response->getTargetUrl());

        // Aucune création : la visite existante est mise à jour.
        self::assertSame(1, (int) $this->em->getRepository(Visit::class)->count([]));
        $this->em->clear();
        $reloaded = $this->em->getRepository(Visit::class)->findOneBy(['reference' => $reference]);
        self::assertNotNull($reloaded);
        self::assertSame($newSlot->format('Y-m-d H:i'), $reloaded->getScheduledAt()?->format('Y-m-d H:i'));
        self::assertSame('Code 4812', $reloaded->getNote());
        // Instantané modificateur posé (touchBy), référence intacte.
        self::assertNotNull($reloaded->getUpdatedAt());
        self::assertSame('First Last', $reloaded->getUpdatedByName());
        // Ni événement dossier, ni email client.
        self::assertSame(0, (int) $this->em->getRepository(\App\Dossier\Entity\DossierEvent::class)->count(['kind' => 'visit_booked']));
        self::assertEmailCount(0);
    }

    public function testMovingItsOwnSlotOnTheSameDayIsAllowed(): void
    {
        // Sans exclusion de la visite elle-même, la garde "même dossier,
        // même adresse, même jour" refuserait ce simple déplacement d'heure.
        $slot = (new \DateTimeImmutable('+9 days'))->setTime(10, 0);
        $visit = $this->persistVisit(scheduledAt: $slot);

        $component = $this->mountEdit($visit);
        $component->formValues = $this->values($visit, scheduledAt: $slot->setTime(16, 30)->format('Y-m-d\TH:i'));

        self::assertInstanceOf(RedirectResponse::class, $component->create($this->em, $this->nullGeocoder()));
        $this->em->clear();
        self::assertSame('16:30', $this->em->getRepository(Visit::class)->findOneBy([])->getScheduledAt()?->format('H:i'));
    }

    public function testOverlappingAnotherVisitOfTheDossierIsStillRefused(): void
    {
        $slot = (new \DateTimeImmutable('+9 days'))->setTime(10, 0);
        // Une AUTRE visite du dossier occupe déjà l'adresse ce jour-là.
        $this->persistVisit(scheduledAt: $slot);
        $edited = $this->persistVisit(scheduledAt: $slot->modify('+2 days'), address: '5 avenue Daumesnil, 75012 Paris');

        $component = $this->mountEdit($edited);
        $component->formValues = $this->values(
            $edited,
            scheduledAt: $slot->setTime(16, 0)->format('Y-m-d\TH:i'),
            address: '12 rue de la Roquette, 75011 Paris',
        );

        try {
            $component->create($this->em, $this->nullGeocoder());
            self::fail('Moving onto another visit of the dossier (same address, same day) must be refused.');
        } catch (\Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException) {
            // Expected.
        }
        $this->em->clear();
        $reloaded = $this->em->getRepository(Visit::class)->findOneBy(['reference' => $edited->getReference()]);
        self::assertSame('5 avenue Daumesnil, 75012 Paris', $reloaded->getAddress(), 'The edited visit did not move.');
    }

    public function testTheDossierIsLockedAgainstForgedValues(): void
    {
        $other = $this->persistDossier('Famille Bernard');
        $visit = $this->persistVisit();

        $component = $this->mountEdit($visit);
        // Valeur forgée dans le POST : le champ est désactivé côté form, le
        // dossier stocké doit gagner.
        $component->formValues = $this->values($visit, dossier: (string) $other->getId());

        self::assertInstanceOf(RedirectResponse::class, $component->create($this->em, $this->nullGeocoder()));
        $this->em->clear();
        $reloaded = $this->em->getRepository(Visit::class)->findOneBy([]);
        self::assertSame($this->dossier->getId(), $reloaded->getDossier()?->getId(), 'The dossier never changes on edit.');
    }

    public function testEditRenderIsPrefilledWithoutDossierPickerNorPhotos(): void
    {
        $visit = $this->persistVisit();
        $visit->setNote('Interphone 4812');
        $this->em->flush();

        $rendered = (string) $this->renderTwigComponent('Visit:VisitForm', [
            'adminPrefix' => self::PREFIX,
            'visit' => $visit,
        ]);

        // Pré-rempli avec les valeurs stockées.
        self::assertStringContainsString('12 rue de la Roquette, 75011 Paris', $rendered);
        self::assertStringContainsString('Interphone 4812', $rendered);
        // Dossier en lecture seule, pas de select.
        self::assertStringContainsString('data-testid="visit-form-dossier-locked"', $rendered);
        self::assertStringNotContainsString('data-testid="visit-form-dossier-button"', $rendered);
        // La section photos n'existe pas en édition (gestion sur la fiche).
        self::assertStringNotContainsString('data-testid="visit-form-photos-toggle"', $rendered);
        // Libellés d'édition sur le bouton principal.
        self::assertStringContainsString('Enregistrer', $rendered);
    }

    public function testEditConfirmModalHasNoNotifyTileAndASimpleText(): void
    {
        $visit = $this->persistVisit();

        $rendered = (string) $this->renderTwigComponent('Visit:VisitForm', [
            'adminPrefix' => self::PREFIX,
            'visit' => $visit,
            'confirmingCreate' => true,
        ]);

        self::assertStringContainsString('data-testid="visit-form-confirm-modal"', $rendered);
        self::assertStringContainsString('Enregistrer les modifications de cette visite ?', $rendered);
        // Pas de tuile email ni de texte "événement au dossier" en édition.
        self::assertStringNotContainsString('data-testid="visit-form-confirm-notify"', $rendered);
        self::assertStringNotContainsString('ajoutée au fil de suivi', $rendered);
    }

    public function testAPastVisitStaysEditableButItsSlotCannotMoveIntoThePast(): void
    {
        $past = (new \DateTimeImmutable('-2 days'))->setTime(10, 0);
        $visit = $this->persistVisit(scheduledAt: $past);

        // Créneau inchangé : éditer la note d'une visite passée fonctionne.
        $component = $this->mountEdit($visit);
        $component->formValues = $this->values($visit, scheduledAt: $past->format('Y-m-d\TH:i'), note: 'Compte OK');
        self::assertInstanceOf(RedirectResponse::class, $component->create($this->em, $this->nullGeocoder()));
        $this->em->clear();
        $reloaded = $this->em->getRepository(Visit::class)->findOneBy([]);
        self::assertSame('Compte OK', $reloaded->getNote());

        // Mais déplacer le créneau VERS le passé reste refusé.
        $component = $this->mountEdit($reloaded);
        $component->formValues = $this->values($reloaded, scheduledAt: (new \DateTimeImmutable('-1 day'))->setTime(11, 0)->format('Y-m-d\TH:i'));
        try {
            $component->create($this->em, $this->nullGeocoder());
            self::fail('Moving the slot into the past must be refused.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            self::assertSame(422, $e->getStatusCode());
        }
        $this->em->clear();
        self::assertSame($past->format('Y-m-d H:i'), $this->em->getRepository(Visit::class)->findOneBy([])->getScheduledAt()?->format('Y-m-d H:i'));
    }

    /** Formulaire monté sur la visite existante, comme la page Modifier. */
    private function mountEdit(Visit $visit): object
    {
        $component = $this->mountTwigComponent('Visit:VisitForm', [
            'adminPrefix' => self::PREFIX,
            'visit' => $visit,
        ]);
        self::assertTrue($component->isEditing());

        return $component;
    }

    /**
     * @return array<string, string>
     */
    private function values(
        Visit $visit,
        ?string $dossier = null,
        ?string $scheduledAt = null,
        string $address = '12 rue de la Roquette, 75011 Paris',
        string $note = '',
    ): array {
        return [
            'dossier' => $dossier ?? (string) $visit->getDossier()?->getId(),
            'assignee' => '',
            'agent' => '',
            'type' => 'property_visit',
            'durationMinutes' => '30',
            'listingUrl' => '',
            'clientPresent' => '1',
            'scheduledAt' => $scheduledAt ?? (string) $visit->getScheduledAt()?->format('Y-m-d\TH:i'),
            'address' => $address,
            'note' => $note,
        ];
    }

    private function persistVisit(?\DateTimeImmutable $scheduledAt = null, string $address = '12 rue de la Roquette, 75011 Paris'): Visit
    {
        $visit = (new Visit())
            ->setReference('VS-'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT))
            ->setDossier($this->dossier)
            ->setScheduledAt($scheduledAt ?? (new \DateTimeImmutable('+2 days'))->setTime(10, 30))
            ->setAddress($address)
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($visit);
        $this->em->flush();

        return $visit;
    }

    private function persistDossier(string $name): Dossier
    {
        $dossier = (new Dossier())
            ->setName($name)
            ->setReference('DS-'.random_int(100000, 999999))
            ->setPairingCode(substr(strtoupper(bin2hex(random_bytes(4))), 0, 6))
            ->setCreatedAt(new \DateTimeImmutable())
            ->setSearch((new DossierSearch())
                ->setBudget(2500)
                ->setAreas('11e, 12e')
                ->setMoveInAt(new \DateTimeImmutable('+3 months'))
                ->setPropertyType('t2,t3')
                ->setStayDuration('long')
                ->setFurnishing('furnished')
                ->setGuarantorType('physical'));
        $this->em->persist($dossier);
        $this->em->flush();

        return $dossier;
    }

    /** Geocoder with no key: short-circuits to null without any request. */
    private function nullGeocoder(): AddressGeocoder
    {
        return new AddressGeocoder(new MockHttpClient(), '');
    }

    private function loginAsAdmin(): void
    {
        $admin = (new User())
            ->setEmail(bin2hex(random_bytes(4)).'@visit-edit-test.local')
            ->setFirstName('First')->setLastName('Last')
            ->setRoles(['ROLE_ADMIN'])->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($admin);
        $this->em->flush();
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($admin, 'main', $admin->getRoles()));
    }
}
