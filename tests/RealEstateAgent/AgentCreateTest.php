<?php

declare(strict_types=1);

namespace App\Tests\RealEstateAgent;

use App\Auth\Entity\User;
use App\RealEstateAgent\Domain\AgencyPosition;
use App\RealEstateAgent\Domain\AgentSpecialty;
use App\RealEstateAgent\Entity\Agency;
use App\RealEstateAgent\Entity\RealEstateAgent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * RealEstateAgent:AgentCreate component behaviour: creation happy path with
 * optional fields, validation failures, and the page-form markup contract.
 */
final class AgentCreateTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private const PREFIX = 'test_admin_prefix_1234567890abcdef';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.RealEstateAgent::class)->execute();
        $this->em->createQuery('DELETE FROM '.Agency::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@agent-create-test.local')->execute();
        $this->loginAsAdmin();
    }

    public function testCreatesAnAgentAndRedirectsToTheList(): void
    {
        $agency = $this->persistAgency('Foncia Paris 11');
        $component = $this->mountComponent();
        $component->agencyId = (int) $agency->getId();

        $component->formValues = [
            'firstName' => 'Jean',
            'lastName' => 'Martin',
            'kind' => 'agency',
            'email' => 'jean@foncia.fr',
            'phone' => '+33611223344',
        ];

        $response = $this->createAction($component);
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertMatchesRegularExpression('~/'.self::PREFIX.'/admin/(agents-immobiliers|real-estate-agents)$~', (string) $response->getTargetUrl());

        /** @var RealEstateAgent|null $agent */
        $agent = $this->em->getRepository(RealEstateAgent::class)->findOneBy(['lastName' => 'Martin']);
        self::assertNotNull($agent);
        self::assertSame('Jean', $agent->getFirstName());
        self::assertSame('Foncia Paris 11', $agent->getAgency()?->getName());
        self::assertSame('jean@foncia.fr', $agent->getEmail());
        self::assertSame('+33611223344', $agent->getPhone());
        self::assertNotNull($agent->getCreatedAt());
    }

    public function testOptionalFieldsCanStayEmptyMakingTheAgentIndependent(): void
    {
        $component = $this->mountComponent();

        $component->formValues = [
            'firstName' => 'Jean',
            'lastName' => 'Martin',
            'kind' => 'independent',
            'email' => '',
            'phone' => '',
        ];

        self::assertInstanceOf(RedirectResponse::class, $this->createAction($component));

        /** @var RealEstateAgent|null $agent */
        $agent = $this->em->getRepository(RealEstateAgent::class)->findOneBy(['lastName' => 'Martin']);
        self::assertNotNull($agent);
        self::assertNull($agent->getAgency(), 'No agency = independent agent.');
        self::assertNull($agent->getEmail());
        self::assertNull($agent->getPhone());
        self::assertSame(0, (int) $this->em->getRepository(Agency::class)->count([]));
    }

    public function testChooseAgencySelectsAnActiveAgency(): void
    {
        $agency = $this->persistAgency('Foncia Paris 11');
        $component = $this->mountComponent();

        $component->chooseAgency((int) $agency->getId());

        self::assertSame((int) $agency->getId(), $component->agencyId);
        self::assertFalse($component->agencyError);
        self::assertSame('Foncia Paris 11', $component->getSelectedAgency()?->name);
    }

    public function testChooseAgencyRejectsAnUnknownId(): void
    {
        $component = $this->mountComponent();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\BadRequestHttpException::class);
        $component->chooseAgency(999999);
    }

    public function testChooseAgencyRejectsADeactivatedAgency(): void
    {
        $agency = $this->persistAgency('Foncia Paris 11', active: false);
        $component = $this->mountComponent();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\BadRequestHttpException::class);
        $component->chooseAgency((int) $agency->getId());
    }

    public function testBlankNameBlocksCreation(): void
    {
        $component = $this->mountComponent();

        $component->formValues = [
            'firstName' => '',
            'lastName' => '',
            'kind' => 'independent',
            'email' => '',
            'phone' => '',
        ];

        try {
            $this->createAction($component);
            self::fail('Expected an unprocessable-entity exception.');
        } catch (HttpExceptionInterface $e) {
            self::assertSame(422, $e->getStatusCode());
        }

        self::assertSame(0, (int) $this->em->getRepository(RealEstateAgent::class)->count([]));
    }

    public function testInvalidEmailBlocksCreation(): void
    {
        $component = $this->mountComponent();

        $component->formValues = [
            'firstName' => 'Jean',
            'lastName' => 'Martin',
            'kind' => 'independent',
            'email' => 'not-an-email',
            'phone' => '',
        ];

        try {
            $this->createAction($component);
            self::fail('Expected an unprocessable-entity exception.');
        } catch (HttpExceptionInterface $e) {
            self::assertSame(422, $e->getStatusCode());
        }

        self::assertSame(0, (int) $this->em->getRepository(RealEstateAgent::class)->count([]));
    }

    public function testPageFormMarkupContract(): void
    {
        $rendered = (string) $this->renderTwigComponent('RealEstateAgent:AgentCreate', [
            'adminPrefix' => self::PREFIX,
        ]);

        self::assertStringContainsString('data-testid="agent-create-form"', $rendered);
        // The form is split into titled sections separated by dividers.
        self::assertSame(4, substr_count($rendered, 'border-t border-gray-950/5'), 'Identity | agency | specialties | contact | note: four dividers.');
        self::assertSame(5, substr_count($rendered, 'tracking-wide text-gray-500 uppercase'), 'Five section micro-titles.');
        self::assertStringContainsString('data-testid="agent-create-note"', $rendered);
        // Specialty chips (multi) and position chips (agency context, the
        // default kind) with their toggle-off live action.
        self::assertStringContainsString('data-testid="agent-create-specialties-block"', $rendered);
        self::assertStringContainsString('data-testid="agent-create-specialty-location"', $rendered);
        self::assertStringContainsString('data-testid="agent-create-position-block"', $rendered);
        self::assertStringContainsString('data-live-action-param="togglePosition"', $rendered);
        // Agency picker: custom details dropdown (selection only) with an
        // inline search filter.
        self::assertStringContainsString('data-testid="agent-create-agency"', $rendered);
        self::assertStringContainsString('data-testid="agent-create-agency-search"', $rendered);
        self::assertStringNotContainsString('<datalist', $rendered);
        self::assertStringContainsString('aria-labelledby="agent-create-title"', $rendered);
        // Anti double-submit guard on the create action.
        self::assertStringContainsString('data-loading="action(create)|addAttribute(disabled)"', $rendered);
        // Cancel is a plain link back to the agents list, not a live action.
        self::assertMatchesRegularExpression('~href="[^"]*/'.self::PREFIX.'/admin/(agents-immobiliers|real-estate-agents)"~', $rendered);
        // The modal chrome is gone: the form is a full-page card.
        self::assertStringNotContainsString('role="dialog"', $rendered);
        self::assertStringNotContainsString('openModal', $rendered);
    }

    public function testAgencyKindWithoutASelectionSetsTheAgencyError(): void
    {
        $component = $this->mountComponent();

        $component->formValues = [
            'firstName' => 'Jean',
            'lastName' => 'Martin',
            'kind' => 'agency',
            'email' => '',
            'phone' => '',
        ];

        try {
            $this->createAction($component);
            self::fail('Expected an unprocessable-entity exception.');
        } catch (HttpExceptionInterface $e) {
            self::assertSame(422, $e->getStatusCode());
        }

        self::assertTrue($component->agencyError, 'The dropdown error message must show.');
        self::assertSame(0, (int) $this->em->getRepository(RealEstateAgent::class)->count([]));
        self::assertSame(0, (int) $this->em->getRepository(Agency::class)->count([]));
    }

    public function testAgencyKindRejectsAnUnknownAgencyId(): void
    {
        $component = $this->mountComponent();
        $component->agencyId = 999999;

        $component->formValues = [
            'firstName' => 'Jean',
            'lastName' => 'Martin',
            'kind' => 'agency',
            'email' => '',
            'phone' => '',
        ];

        try {
            $this->createAction($component);
            self::fail('Expected an unprocessable-entity exception.');
        } catch (HttpExceptionInterface $e) {
            self::assertSame(422, $e->getStatusCode());
        }

        self::assertTrue($component->agencyError);
        self::assertSame(0, (int) $this->em->getRepository(RealEstateAgent::class)->count([]));
    }

    public function testAgencyKindRejectsADeactivatedAgency(): void
    {
        $agency = $this->persistAgency('Foncia Paris 11', active: false);
        $component = $this->mountComponent();
        $component->agencyId = (int) $agency->getId();

        $component->formValues = [
            'firstName' => 'Jean',
            'lastName' => 'Martin',
            'kind' => 'agency',
            'email' => '',
            'phone' => '',
        ];

        try {
            $this->createAction($component);
            self::fail('Expected an unprocessable-entity exception.');
        } catch (HttpExceptionInterface $e) {
            self::assertSame(422, $e->getStatusCode());
        }

        self::assertTrue($component->agencyError);
        self::assertSame(0, (int) $this->em->getRepository(RealEstateAgent::class)->count([]));
    }

    public function testIndependentKindIgnoresALeftoverAgencySelection(): void
    {
        $agency = $this->persistAgency('Foncia Paris 11');
        $component = $this->mountComponent();

        // Picked an agency, then switched the chip back to independent.
        $component->agencyId = (int) $agency->getId();
        $component->formValues = [
            'firstName' => 'Jean',
            'lastName' => 'Martin',
            'kind' => 'independent',
            'email' => '',
            'phone' => '',
        ];

        self::assertInstanceOf(RedirectResponse::class, $this->createAction($component));

        $agent = $this->em->getRepository(RealEstateAgent::class)->findOneBy(['lastName' => 'Martin']);
        self::assertNotNull($agent);
        self::assertNull($agent->getAgency());
    }

    public function testDropdownListsActiveAgenciesWithAddressAndExcludesDeactivatedOnes(): void
    {
        $this->persistAgency('Foncia Paris 11', address: '12 rue de la Roquette, 75011 Paris');
        $this->persistAgency('Orpi Bastille');
        $this->persistAgency('Guy Hoquet Nation', active: false);

        $rendered = (string) $this->renderTwigComponent('RealEstateAgent:AgentCreate', [
            'adminPrefix' => self::PREFIX,
        ]);

        // Each option is a live#action button on chooseAgency.
        self::assertStringContainsString('data-live-action-param="chooseAgency"', $rendered);
        self::assertStringContainsString('Foncia Paris 11', $rendered);
        self::assertStringContainsString('12 rue de la Roquette, 75011 Paris', $rendered);
        // No address = a plain dash on the second line.
        self::assertStringContainsString('Orpi Bastille', $rendered);
        self::assertStringContainsString('>-</span>', $rendered);
        // A deactivated agency must not be selectable anymore.
        self::assertStringNotContainsString('Guy Hoquet Nation', $rendered);
    }

    public function testStoresMultipleSpecialtiesAndTheAgencyPosition(): void
    {
        $agency = $this->persistAgency('Foncia Paris 11');
        $component = $this->mountComponent();
        $component->agencyId = (int) $agency->getId();

        $component->formValues = [
            'firstName' => 'Jean',
            'lastName' => 'Martin',
            'kind' => 'agency',
            'position' => 'consultant_rental',
            'specialties' => ['location', 'transaction'],
            'professionalCards' => ['t', 'g'],
            'email' => '',
            'phone' => '',
        ];

        self::assertInstanceOf(RedirectResponse::class, $this->createAction($component));

        $agent = $this->em->getRepository(RealEstateAgent::class)->findOneBy(['lastName' => 'Martin']);
        self::assertNotNull($agent);
        self::assertSame(AgencyPosition::ConsultantRental, $agent->getPosition());
        self::assertSame(
            [AgentSpecialty::Location, AgentSpecialty::Transaction],
            $agent->getSpecialties(),
            'An agent can handle both rentals and sales.',
        );
        self::assertSame(
            [\App\RealEstateAgent\Domain\ProfessionalCard::Transaction, \App\RealEstateAgent\Domain\ProfessionalCard::Gestion],
            $agent->getProfessionalCards(),
            'Cartes loi Hoguet stored alongside the specialties.',
        );
    }

    public function testSpecialtiesAndPositionAreOptional(): void
    {
        $agency = $this->persistAgency('Foncia Paris 11');
        $component = $this->mountComponent();
        $component->agencyId = (int) $agency->getId();

        $component->formValues = [
            'firstName' => 'Jean',
            'lastName' => 'Martin',
            'kind' => 'agency',
            'position' => '',
            'specialties' => [],
            'email' => '',
            'phone' => '',
        ];

        self::assertInstanceOf(RedirectResponse::class, $this->createAction($component));

        $agent = $this->em->getRepository(RealEstateAgent::class)->findOneBy(['lastName' => 'Martin']);
        self::assertNotNull($agent);
        self::assertNull($agent->getPosition());
        self::assertSame([], $agent->getSpecialties());
    }

    public function testIndependentKindDropsALeftoverPosition(): void
    {
        $component = $this->mountComponent();

        // Picked a position, then switched the kind chip to independent:
        // the position is out of context and must not be persisted.
        $component->formValues = [
            'firstName' => 'Jean',
            'lastName' => 'Martin',
            'kind' => 'independent',
            'position' => 'manager',
            'specialties' => ['location'],
            'email' => '',
            'phone' => '',
        ];

        self::assertInstanceOf(RedirectResponse::class, $this->createAction($component));

        $agent = $this->em->getRepository(RealEstateAgent::class)->findOneBy(['lastName' => 'Martin']);
        self::assertNotNull($agent);
        self::assertNull($agent->getPosition(), 'No position without an agency.');
        self::assertSame([AgentSpecialty::Location], $agent->getSpecialties(), 'Specialties are kept: they are not agency-bound.');
    }

    public function testStoresTheInternalNote(): void
    {
        $component = $this->mountComponent();

        $component->formValues = [
            'firstName' => 'Jean',
            'lastName' => 'Martin',
            'kind' => 'independent',
            'email' => '',
            'phone' => '',
            'note' => 'Réactif sur WhatsApp, préfère les visites le matin.',
        ];

        self::assertInstanceOf(RedirectResponse::class, $this->createAction($component));

        $agent = $this->em->getRepository(RealEstateAgent::class)->findOneBy(['lastName' => 'Martin']);
        self::assertSame('Réactif sur WhatsApp, préfère les visites le matin.', $agent?->getNote());
    }

    public function testABlankNoteIsStoredAsNull(): void
    {
        $component = $this->mountComponent();

        $component->formValues = [
            'firstName' => 'Jean',
            'lastName' => 'Martin',
            'kind' => 'independent',
            'email' => '',
            'phone' => '',
            'note' => '   ',
        ];

        self::assertInstanceOf(RedirectResponse::class, $this->createAction($component));

        $agent = $this->em->getRepository(RealEstateAgent::class)->findOneBy(['lastName' => 'Martin']);
        self::assertNull($agent?->getNote());
    }

    public function testANoteOverTwoThousandCharactersBlocksCreation(): void
    {
        $component = $this->mountComponent();

        $component->formValues = [
            'firstName' => 'Jean',
            'lastName' => 'Martin',
            'kind' => 'independent',
            'email' => '',
            'phone' => '',
            'note' => str_repeat('a', 2001),
        ];

        try {
            $this->createAction($component);
            self::fail('Expected an unprocessable-entity exception.');
        } catch (HttpExceptionInterface $e) {
            self::assertSame(422, $e->getStatusCode());
        }

        self::assertSame(0, (int) $this->em->getRepository(RealEstateAgent::class)->count([]));
    }

    public function testTogglePositionSelectsAndUnselects(): void
    {
        $component = $this->mountComponent();

        $component->togglePosition('manager');
        self::assertSame('manager', $component->formValues['position']);

        // A different chip replaces the current one (single-select)...
        $component->togglePosition('assistant');
        self::assertSame('assistant', $component->formValues['position']);

        // ...and clicking the active chip clears it (toggle-off).
        $component->togglePosition('assistant');
        self::assertSame('', $component->formValues['position']);
    }

    public function testDuplicateWarningMatchesAnExistingEmailCaseInsensitively(): void
    {
        $existing = (new RealEstateAgent())
            ->setFirstName('Jean')->setLastName('Martin')
            ->setEmail('jean.martin@orpi.fr')->setPhone('+33611111111');
        $existing->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($existing);
        $this->em->flush();

        $component = $this->mountComponent();
        $component->formValues['email'] = 'JEAN.MARTIN@orpi.fr';
        $component->formValues['phone'] = '';

        $duplicate = $component->getDuplicate();
        self::assertNotNull($duplicate, 'The warning must surface the existing profile.');
        self::assertSame('Jean Martin', $duplicate['name']);
        self::assertSame('email', $duplicate['field']);
        self::assertSame($existing->getReference(), $duplicate['reference']);
    }

    public function testDuplicateWarningMatchesAnExistingPhoneAndStaysQuietOtherwise(): void
    {
        $existing = (new RealEstateAgent())
            ->setFirstName('Jean')->setLastName('Martin')
            ->setEmail('jean.martin@orpi.fr')->setPhone('+33611111111');
        $existing->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($existing);
        $this->em->flush();

        $component = $this->mountComponent();
        $component->formValues['email'] = 'autre@exemple.fr';
        $component->formValues['phone'] = '+33611111111';
        self::assertSame('phone', $component->getDuplicate()['field'] ?? null);

        // Unique coordinates: no band, and empty fields never match anything.
        $component->formValues['phone'] = '+33622222222';
        self::assertNull($component->getDuplicate());
        $component->formValues['email'] = '';
        $component->formValues['phone'] = '';
        self::assertNull($component->getDuplicate());
    }

    public function testDuplicateWarningDoesNotBlockCreation(): void
    {
        $existing = (new RealEstateAgent())
            ->setFirstName('Jean')->setLastName('Martin')
            ->setEmail('jean.martin@orpi.fr');
        $existing->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($existing);
        $this->em->flush();

        $component = $this->mountComponent();
        $component->formValues = [
            'firstName' => 'Jeanne',
            'lastName' => 'Marchand',
            'kind' => 'independent',
            'email' => 'jean.martin@orpi.fr',
            'phone' => '',
        ];

        self::assertInstanceOf(RedirectResponse::class, $this->createAction($component));
        self::assertSame(2, (int) $this->em->getRepository(RealEstateAgent::class)->count([]));
    }

    private function createAction(object $component): ?RedirectResponse
    {
        return $component->create($this->em, self::getContainer()->get(\Symfony\Contracts\Translation\TranslatorInterface::class));
    }

    private function persistAgency(string $name, ?string $address = null, bool $active = true): Agency
    {
        $agency = (new Agency())
            ->setName($name)
            ->setAddress($address)
            ->setCreatedAt(new \DateTimeImmutable());
        if (!$active) {
            $agency->setDeactivatedAt(new \DateTimeImmutable());
        }
        $this->em->persist($agency);
        $this->em->flush();

        return $agency;
    }

    private function mountComponent(): object
    {
        return $this->mountTwigComponent('RealEstateAgent:AgentCreate', ['adminPrefix' => self::PREFIX]);
    }

    private function loginAsAdmin(): void
    {
        $admin = (new User())
            ->setEmail(bin2hex(random_bytes(4)).'@agent-create-test.local')
            ->setFirstName('First')->setLastName('Last')
            ->setRoles(['ROLE_ADMIN'])->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($admin);
        $this->em->flush();

        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($admin, 'main', $admin->getRoles()));
    }
}
