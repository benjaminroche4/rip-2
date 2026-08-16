<?php

declare(strict_types=1);

namespace App\Tests\Dossier;

use App\Auth\Entity\User;
use App\Dossier\Domain\ContactLanguage;
use App\Dossier\Domain\DossierPersonRole;
use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierPerson;
use App\Dossier\Service\DossierDriveProvisioner;
use App\Dossier\Service\DossierNumberGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * Dossier:DossierCreate component behaviour: creation happy path (persons,
 * positions, primary tenant tag), validation failures, and the household
 * composition rules (max 2 per role, tenant required).
 */
final class DossierCreateTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private const PREFIX = 'test_admin_prefix_1234567890abcdef';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.Dossier::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@dossier-create-test.local')->execute();
        $this->loginAsAdmin();
    }

    public function testCreatesADossierWithPrimaryTenantAndFollowUp(): void
    {
        $component = $this->mountComponent();

        $component->formValues['persons'][0] = $this->personValues('Jean', 'Dupont', 'jean@example.com', DossierPersonRole::TENANT, phone: '+33611223344');
        $component->addPerson(self::getContainer()->get('property_accessor'));
        $component->formValues['persons'][1] = $this->personValues('Marie', 'Durand', 'marie@example.com', DossierPersonRole::FOLLOW_UP, language: 'en');

        $response = $this->createAction($component);
        self::assertInstanceOf(RedirectResponse::class, $response);
        // Redirects straight to the new dossier's detail page.
        self::assertMatchesRegularExpression('~/'.self::PREFIX.'/admin/dossiers/DS-\d{6}$~', (string) $response->getTargetUrl());

        /** @var Dossier|null $dossier */
        $dossier = $this->em->getRepository(Dossier::class)->findOneBy(['name' => 'Dupont']);
        self::assertNotNull($dossier);
        self::assertCount(2, $dossier->getPersons());

        /** @var list<DossierPerson> $persons */
        $persons = $dossier->getPersons()->toArray();
        self::assertSame('Jean', $persons[0]->getFirstName());
        self::assertSame(DossierPersonRole::TENANT, $persons[0]->getRole());
        self::assertTrue($persons[0]->isPrimaryContact(), 'The only tenant carries the primary tag.');
        self::assertSame('+33611223344', $persons[0]->getPhone());
        self::assertSame(0, $persons[0]->getPosition());

        self::assertSame(DossierPersonRole::FOLLOW_UP, $persons[1]->getRole());
        self::assertFalse($persons[1]->isPrimaryContact());
        self::assertSame(ContactLanguage::EN, $persons[1]->getLanguage());
        self::assertSame(1, $persons[1]->getPosition());

        // Random public identifiers, set at creation.
        self::assertMatchesRegularExpression('/^DS-\d{6}$/', (string) $dossier->getReference());
        self::assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{6}$/', (string) $dossier->getPairingCode());
    }

    public function testOfferIsRequiredAndPersisted(): void
    {
        $component = $this->mountComponent();
        $component->formValues['persons'][0] = $this->personValues('Jean', 'Dupont', 'jean@example.com', DossierPersonRole::TENANT, phone: '+33611223344');

        // Sans formule : la création est refusée, le flag d'erreur s'affiche.
        try {
            $component->create($this->em, new DossierNumberGenerator($this->em->getRepository(Dossier::class)), self::getContainer()->get(DossierDriveProvisioner::class));
            self::fail('Expected an unprocessable-entity exception.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            self::assertSame(422, $e->getStatusCode());
        }
        self::assertTrue($component->offerMissing);
        self::assertNull($this->em->getRepository(Dossier::class)->findOneBy(['name' => 'Dupont']));

        $component->chooseOffer('confie');
        self::assertFalse($component->offerMissing);
        $this->createAction($component);

        $dossier = $this->em->getRepository(Dossier::class)->findOneBy(['name' => 'Dupont']);
        self::assertSame('confie', $dossier->getOffer());
    }

    public function testMalformedPhoneIsRejected(): void
    {
        $component = $this->mountComponent();

        $component->formValues['persons'][0] = $this->personValues('Jean', 'Dupont', 'jean@example.com', DossierPersonRole::TENANT, phone: 'call me maybe');

        try {
            $this->createAction($component);
            self::fail('Expected an unprocessable-entity exception.');
        } catch (HttpExceptionInterface $e) {
            self::assertSame(422, $e->getStatusCode());
        }

        self::assertSame(0, (int) $this->em->getRepository(Dossier::class)->count([]));
    }

    public function testOpenModalMarkupIsAccessible(): void
    {
        $rendered = (string) $this->renderTwigComponent('Dossier:DossierCreate', [
            'adminPrefix' => self::PREFIX,
            'open' => true,
        ]);

        self::assertStringContainsString('role="dialog"', $rendered);
        self::assertStringContainsString('aria-modal="true"', $rendered);
        self::assertStringContainsString('aria-labelledby="dossier-create-title"', $rendered);
        // Static modal, on purpose: the form's data-model on(change) re-renders
        // the component while the modal is open, and a morph would strip the
        // runtime data-entered attribute of modal-anim (invisible modal).
        self::assertStringContainsString('data-controller="modal-focus instant-exit"', $rendered);
        self::assertStringNotContainsString('modal-anim', $rendered);
        self::assertStringNotContainsString('data-entered', $rendered);
        // Closing is async (live round trip): every close path hides the
        // overlay synchronously so no frozen intermediate frame shows.
        self::assertStringContainsString('data-action="instant-exit#run live#action"', $rendered);
        // Anti double-submit guard on the create action.
        self::assertStringContainsString('data-loading="action(create)|addAttribute(disabled)"', $rendered);
    }

    public function testPrimaryTagCanBeMovedToTheSecondTenant(): void
    {
        $component = $this->mountComponent();

        $component->formValues['persons'][0] = $this->personValues('Jean', 'Dupont', 'jean@example.com', DossierPersonRole::TENANT);
        $component->addPerson(self::getContainer()->get('property_accessor'));
        $component->formValues['persons'][1] = $this->personValues('Paul', 'Martin', 'paul@example.com', DossierPersonRole::TENANT);

        $component->choosePrimary(1);
        self::assertSame(1, $component->getEffectivePrimaryKey());

        self::assertInstanceOf(RedirectResponse::class, $this->createAction($component));

        $dossier = $this->em->getRepository(Dossier::class)->findOneBy(['name' => 'Martin & Dupont']);
        self::assertNotNull($dossier);
        $persons = $dossier->getPersons()->toArray();
        self::assertFalse($persons[0]->isPrimaryContact());
        self::assertTrue($persons[1]->isPrimaryContact());
    }

    public function testPrimaryTagCannotTargetAFollowUpPerson(): void
    {
        $component = $this->mountComponent();

        $component->formValues['persons'][0] = $this->personValues('Jean', 'Dupont', 'jean@example.com', DossierPersonRole::TENANT);
        $component->addPerson(self::getContainer()->get('property_accessor'));
        $component->formValues['persons'][1] = $this->personValues('Marie', 'Durand', 'marie@example.com', DossierPersonRole::FOLLOW_UP);

        $component->choosePrimary(1);

        self::assertSame(0, $component->getEffectivePrimaryKey(), 'The tag stays on the tenant.');
    }

    public function testInvalidFieldsBlockCreation(): void
    {
        $component = $this->mountComponent();

        $component->formValues['persons'][0] = $this->personValues('J', 'Dupont', 'not-an-email', DossierPersonRole::TENANT);

        try {
            $this->createAction($component);
            self::fail('Expected an unprocessable-entity exception.');
        } catch (HttpExceptionInterface $e) {
            self::assertSame(422, $e->getStatusCode());
        }

        self::assertSame(0, (int) $this->em->getRepository(Dossier::class)->count([]));
    }

    /**
     * Regression: an added person submitted without picking the language
     * radio sends null — that must surface as a 422 field error, not a
     * PropertyAccessor type crash on DossierPerson::setLanguage().
     */
    public function testMissingLanguageIsAFieldErrorNotACrash(): void
    {
        $component = $this->mountComponent();

        $component->formValues['persons'][0] = $this->personValues('Jean', 'Dupont', 'jean@example.com', DossierPersonRole::TENANT, language: '');

        try {
            $this->createAction($component);
            self::fail('Expected an unprocessable-entity exception.');
        } catch (HttpExceptionInterface $e) {
            self::assertSame(422, $e->getStatusCode());
        }

        self::assertSame(0, (int) $this->em->getRepository(Dossier::class)->count([]));
    }

    public function testADossierWithoutTenantIsRejected(): void
    {
        $component = $this->mountComponent();

        $component->formValues['persons'][0] = $this->personValues('Marie', 'Durand', 'marie@example.com', DossierPersonRole::FOLLOW_UP);

        try {
            $this->createAction($component);
            self::fail('Expected an unprocessable-entity exception.');
        } catch (HttpExceptionInterface $e) {
            self::assertSame(422, $e->getStatusCode());
        }

        self::assertSame(0, (int) $this->em->getRepository(Dossier::class)->count([]));
    }

    public function testMoreThanTwoTenantsIsRejected(): void
    {
        $component = $this->mountComponent();
        $accessor = self::getContainer()->get('property_accessor');

        $component->formValues['persons'][0] = $this->personValues('A', 'Aa', 'a@example.com', DossierPersonRole::TENANT);
        $component->addPerson($accessor);
        $component->formValues['persons'][1] = $this->personValues('B', 'Bb', 'b@example.com', DossierPersonRole::TENANT);
        $component->addPerson($accessor);
        $component->formValues['persons'][2] = $this->personValues('C', 'Cc', 'c@example.com', DossierPersonRole::TENANT);

        try {
            $this->createAction($component);
            self::fail('Expected an unprocessable-entity exception.');
        } catch (HttpExceptionInterface $e) {
            self::assertSame(422, $e->getStatusCode());
        }

        self::assertSame(0, (int) $this->em->getRepository(Dossier::class)->count([]));
    }

    public function testAddPersonIsCappedAtFour(): void
    {
        $component = $this->mountComponent();
        $accessor = self::getContainer()->get('property_accessor');

        for ($i = 0; $i < 6; ++$i) {
            $component->addPerson($accessor);
        }

        self::assertCount(Dossier::MAX_PERSONS, $component->formValues['persons']);
    }

    public function testRemovePersonLeavesAHoleAndCreateRepairsPositions(): void
    {
        $component = $this->mountComponent();
        $accessor = self::getContainer()->get('property_accessor');

        $component->formValues['persons'][0] = $this->personValues('Jean', 'Dupont', 'jean@example.com', DossierPersonRole::TENANT);
        $component->addPerson($accessor);
        $component->formValues['persons'][1] = $this->personValues('Marie', 'Durand', 'marie@example.com', DossierPersonRole::FOLLOW_UP);
        $component->addPerson($accessor);
        $component->formValues['persons'][2] = $this->personValues('Paul', 'Martin', 'paul@example.com', DossierPersonRole::FOLLOW_UP);

        $component->removePerson($accessor, 1);

        self::assertInstanceOf(RedirectResponse::class, $this->createAction($component));

        $dossier = $this->em->getRepository(Dossier::class)->findOneBy(['name' => 'Dupont']);
        self::assertNotNull($dossier);
        $persons = $dossier->getPersons()->toArray();
        self::assertCount(2, $persons);
        self::assertSame(['Jean', 'Paul'], array_map(fn (DossierPerson $p) => $p->getFirstName(), $persons));
        self::assertSame([0, 1], array_map(fn (DossierPerson $p) => $p->getPosition(), $persons));
    }

    /**
     * @return array<string, string>
     */
    private function personValues(
        string $firstName,
        string $lastName,
        string $email,
        DossierPersonRole $role,
        string $phone = '',
        string $language = 'fr',
    ): array {
        return [
            'role' => $role->value,
            'firstName' => $firstName,
            'lastName' => $lastName,
            'email' => $email,
            'phone' => $phone,
            'language' => $language,
        ];
    }

    private function createAction(object $component): ?RedirectResponse
    {
        /** @var \App\Dossier\Repository\DossierRepository $repository */
        $repository = $this->em->getRepository(Dossier::class);

        // La formule est obligatoire : les tests qui valident d'autres
        // comportements en choisissent une par défaut.
        if ('' === $component->offer) {
            $component->chooseOffer('accompagne');
        }

        return $component->create($this->em, new DossierNumberGenerator($repository), self::getContainer()->get(DossierDriveProvisioner::class));
    }

    private function mountComponent(): object
    {
        return $this->mountTwigComponent('Dossier:DossierCreate', ['adminPrefix' => self::PREFIX]);
    }

    private function loginAsAdmin(): void
    {
        $admin = (new User())
            ->setEmail(bin2hex(random_bytes(4)).'@dossier-create-test.local')
            ->setFirstName('First')->setLastName('Last')
            ->setRoles(['ROLE_ADMIN'])->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($admin);
        $this->em->flush();

        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($admin, 'main', $admin->getRoles()));
    }
}
