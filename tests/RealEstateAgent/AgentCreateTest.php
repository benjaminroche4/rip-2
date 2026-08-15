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
        $component = $this->mountComponent();

        $component->formValues = [
            'firstName' => 'Jean',
            'lastName' => 'Martin',
            'kind' => 'agency',
            'agencyName' => 'Foncia Paris 11',
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
            'agencyName' => '',
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

    public function testTwoAgentsWithTheSameAgencyNameShareOneAgency(): void
    {
        foreach ([['Jean', 'Martin', 'Foncia Paris 11'], ['Paul', 'Durand', 'foncia paris 11']] as [$first, $last, $agency]) {
            $component = $this->mountComponent();
            $component->formValues = [
                'firstName' => $first,
                'lastName' => $last,
                'kind' => 'agency',
                'agencyName' => $agency,
                'email' => '',
                'phone' => '',
            ];
            self::assertInstanceOf(RedirectResponse::class, $this->createAction($component));
        }

        // Case-insensitive reuse: one agency row, both agents linked to it.
        self::assertSame(1, (int) $this->em->getRepository(Agency::class)->count([]));
        $martin = $this->em->getRepository(RealEstateAgent::class)->findOneBy(['lastName' => 'Martin']);
        $durand = $this->em->getRepository(RealEstateAgent::class)->findOneBy(['lastName' => 'Durand']);
        self::assertNotNull($martin?->getAgency());
        self::assertSame($martin->getAgency()?->getId(), $durand?->getAgency()?->getId());
        self::assertSame('Foncia Paris 11', $durand?->getAgency()?->getName(), 'The first spelling wins.');
    }

    public function testBlankNameBlocksCreation(): void
    {
        $component = $this->mountComponent();

        $component->formValues = [
            'firstName' => '',
            'lastName' => '',
            'kind' => 'independent',
            'agencyName' => '',
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
            'agencyName' => '',
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
        // Specialty chips (multi) and position chips (agency context, the
        // default kind) with their toggle-off live action.
        self::assertStringContainsString('data-testid="agent-create-specialties-block"', $rendered);
        self::assertStringContainsString('data-testid="agent-create-specialty-location"', $rendered);
        self::assertStringContainsString('data-testid="agent-create-position-block"', $rendered);
        self::assertStringContainsString('data-live-action-param="togglePosition"', $rendered);
        self::assertStringContainsString('aria-labelledby="agent-create-title"', $rendered);
        // Anti double-submit guard on the create action.
        self::assertStringContainsString('data-loading="action(create)|addAttribute(disabled)"', $rendered);
        // Cancel is a plain link back to the agents list, not a live action.
        self::assertMatchesRegularExpression('~href="[^"]*/'.self::PREFIX.'/admin/(agents-immobiliers|real-estate-agents)"~', $rendered);
        // The modal chrome is gone: the form is a full-page card.
        self::assertStringNotContainsString('role="dialog"', $rendered);
        self::assertStringNotContainsString('openModal', $rendered);
    }

    public function testAgencyKindRequiresTheAgencyName(): void
    {
        $component = $this->mountComponent();

        $component->formValues = [
            'firstName' => 'Jean',
            'lastName' => 'Martin',
            'kind' => 'agency',
            'agencyName' => '   ',
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
        self::assertSame(0, (int) $this->em->getRepository(Agency::class)->count([]));
    }

    public function testIndependentKindIgnoresALeftoverAgencyName(): void
    {
        $component = $this->mountComponent();

        // Typed an agency name, then switched the chip back to independent.
        $component->formValues = [
            'firstName' => 'Jean',
            'lastName' => 'Martin',
            'kind' => 'independent',
            'agencyName' => 'Foncia Paris 11',
            'email' => '',
            'phone' => '',
        ];

        self::assertInstanceOf(RedirectResponse::class, $this->createAction($component));

        $agent = $this->em->getRepository(RealEstateAgent::class)->findOneBy(['lastName' => 'Martin']);
        self::assertNotNull($agent);
        self::assertNull($agent->getAgency());
        self::assertSame(0, (int) $this->em->getRepository(Agency::class)->count([]), 'No agency is created.');
    }

    public function testStoresMultipleSpecialtiesAndTheAgencyPosition(): void
    {
        $component = $this->mountComponent();

        $component->formValues = [
            'firstName' => 'Jean',
            'lastName' => 'Martin',
            'kind' => 'agency',
            'agencyName' => 'Foncia Paris 11',
            'position' => 'consultant_rental',
            'specialties' => ['location', 'transaction'],
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
    }

    public function testSpecialtiesAndPositionAreOptional(): void
    {
        $component = $this->mountComponent();

        $component->formValues = [
            'firstName' => 'Jean',
            'lastName' => 'Martin',
            'kind' => 'agency',
            'agencyName' => 'Foncia Paris 11',
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
            'agencyName' => '',
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

    private function createAction(object $component): ?RedirectResponse
    {
        return $component->create($this->em, self::getContainer()->get('translator'));
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
