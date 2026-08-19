<?php

declare(strict_types=1);

namespace App\Tests\RealEstateAgent;

use App\Auth\Entity\User;
use App\RealEstateAgent\Entity\Agency;
use App\RealEstateAgent\Entity\Brand;
use App\RealEstateAgent\Entity\RealEstateAgent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * RealEstateAgent:AgencyCreate behaviour: standalone agency creation, the
 * unique-name guard (case-insensitive), blank rejection, and the page-form
 * markup contract.
 */
final class AgencyCreateTest extends KernelTestCase
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
        $this->em->createQuery('DELETE FROM '.Brand::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@agency-create-test.local')->execute();
        $this->loginAsAdmin();
    }

    public function testCreatesAnAgencyAndRedirectsToTheList(): void
    {
        $component = $this->mountComponent();
        $component->formValues = [
            'name' => 'Foncia Paris 11',
            'brandName' => '',
            'address' => '12 rue de la Roquette, 75011 Paris',
            'phone' => '+33144556677',
            'email' => 'paris11@foncia.fr',
        ];

        $response = $this->createAction($component);
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertMatchesRegularExpression('~/'.self::PREFIX.'/admin/(agents-immobiliers|real-estate-agents)\?view=agencies$~', (string) $response->getTargetUrl());

        $agency = $this->em->getRepository(Agency::class)->findOneBy(['name' => 'Foncia Paris 11']);
        self::assertNotNull($agency);
        self::assertNotNull($agency->getCreatedAt());
        self::assertSame('12 rue de la Roquette, 75011 Paris', $agency->getAddress());
        self::assertSame('+33144556677', $agency->getPhone());
        self::assertSame('paris11@foncia.fr', $agency->getEmail());
        // Empty brand = no brand at all, nothing staged behind the scenes.
        self::assertNull($agency->getBrand());
        self::assertSame(0, (int) $this->em->getRepository(Brand::class)->count([]));
    }

    public function testOptionalDetailsCanStayEmpty(): void
    {
        $component = $this->mountComponent();
        $component->formValues = ['name' => 'Foncia Paris 11', 'brandName' => '', 'address' => '', 'phone' => '', 'email' => ''];

        self::assertInstanceOf(RedirectResponse::class, $this->createAction($component));

        $agency = $this->em->getRepository(Agency::class)->findOneBy(['name' => 'Foncia Paris 11']);
        self::assertNotNull($agency);
        self::assertNull($agency->getBrand());
        self::assertNull($agency->getAddress());
        self::assertNull($agency->getPhone());
        self::assertNull($agency->getEmail());
    }

    public function testCreatesTheBrandTypedInTheAutocompleteField(): void
    {
        $component = $this->mountComponent();
        $component->formValues = ['name' => 'Foncia Paris 11', 'brandName' => 'Foncia', 'address' => '', 'phone' => '', 'email' => ''];

        self::assertInstanceOf(RedirectResponse::class, $this->createAction($component));

        $agency = $this->em->getRepository(Agency::class)->findOneBy(['name' => 'Foncia Paris 11']);
        self::assertSame('Foncia', $agency?->getBrand()?->getName());
        self::assertSame(1, (int) $this->em->getRepository(Brand::class)->count([]));
    }

    public function testReusesAnExistingBrandCaseInsensitively(): void
    {
        $this->em->persist(new Brand('Foncia'));
        $this->em->flush();

        $component = $this->mountComponent();
        $component->formValues = ['name' => 'Foncia Paris 11', 'brandName' => '  foncia  ', 'address' => '', 'phone' => '', 'email' => ''];

        self::assertInstanceOf(RedirectResponse::class, $this->createAction($component));

        // One brand only: the first spelling wins, no duplicate row.
        self::assertSame(1, (int) $this->em->getRepository(Brand::class)->count([]));
        $agency = $this->em->getRepository(Agency::class)->findOneBy(['name' => 'Foncia Paris 11']);
        self::assertSame('Foncia', $agency?->getBrand()?->getName());
    }

    public function testInvalidEmailBlocksCreation(): void
    {
        $component = $this->mountComponent();
        $component->formValues = ['name' => 'Foncia Paris 11', 'brandName' => '', 'address' => '', 'phone' => '', 'email' => 'not-an-email'];

        try {
            $this->createAction($component);
            self::fail('Expected an unprocessable-entity exception.');
        } catch (HttpExceptionInterface $e) {
            self::assertSame(422, $e->getStatusCode());
        }

        self::assertSame(0, (int) $this->em->getRepository(Agency::class)->count([]));
    }

    public function testRejectsADuplicateNameCaseInsensitively(): void
    {
        $this->em->persist((new Agency())->setName('Foncia')->setCreatedAt(new \DateTimeImmutable()));
        $this->em->flush();

        $component = $this->mountComponent();
        $component->formValues = ['name' => '  foncia  '];

        try {
            $this->createAction($component);
            self::fail('Expected an unprocessable-entity exception.');
        } catch (HttpExceptionInterface $e) {
            self::assertSame(422, $e->getStatusCode());
        }

        // Still a single agency: the duplicate was refused, not merged.
        self::assertSame(1, (int) $this->em->getRepository(Agency::class)->count([]));
    }

    public function testBlankNameBlocksCreation(): void
    {
        $component = $this->mountComponent();
        $component->formValues = ['name' => '   '];

        try {
            $this->createAction($component);
            self::fail('Expected an unprocessable-entity exception.');
        } catch (HttpExceptionInterface $e) {
            self::assertSame(422, $e->getStatusCode());
        }

        self::assertSame(0, (int) $this->em->getRepository(Agency::class)->count([]));
    }

    public function testPageFormMarkupContract(): void
    {
        $rendered = (string) $this->renderTwigComponent('RealEstateAgent:AgencyCreate', [
            'adminPrefix' => self::PREFIX,
        ]);

        self::assertStringContainsString('data-testid="agency-create-form"', $rendered);
        // Brand autocomplete: free text over a datalist of known brands.
        self::assertStringContainsString('data-testid="agency-create-brand"', $rendered);
        self::assertStringContainsString('list="agency-create-brand-options"', $rendered);
        self::assertStringContainsString('data-testid="agency-create-address"', $rendered);
        self::assertStringContainsString('data-testid="agency-create-email"', $rendered);
        // The form is split into titled sections separated by dividers.
        self::assertSame(4, substr_count($rendered, 'border-t border-gray-950/5'), 'Identity | address | specialties | contact | note: four dividers.');
        self::assertSame(5, substr_count($rendered, 'tracking-wide text-gray-500 uppercase'), 'Five section micro-titles.');
        // Address field: Google Places assist (same wiring as the estimation
        // form), the submitted value stays the plain text input.
        self::assertStringContainsString('data-controller="places-autocomplete"', $rendered);
        self::assertStringContainsString('data-places-autocomplete-target="input"', $rendered);
        self::assertStringContainsString('data-places-autocomplete-target="results"', $rendered);
        self::assertStringContainsString('input->places-autocomplete#search', $rendered);
        self::assertStringContainsString('aria-labelledby="agency-create-title"', $rendered);
        self::assertStringContainsString('data-loading="action(create)|addAttribute(disabled)"', $rendered);
        // Cancel is a plain link back to the agents list, not a live action.
        self::assertMatchesRegularExpression('~href="[^"]*/'.self::PREFIX.'/admin/(agents-immobiliers|real-estate-agents)"~', $rendered);
        // The modal chrome is gone: the form is a full-page card.
        self::assertStringNotContainsString('role="dialog"', $rendered);
        self::assertStringNotContainsString('openModal', $rendered);
    }

    private function createAction(object $component): ?RedirectResponse
    {
        return $component->create($this->em, self::getContainer()->get('translator'));
    }

    private function mountComponent(): object
    {
        return $this->mountTwigComponent('RealEstateAgent:AgencyCreate', ['adminPrefix' => self::PREFIX]);
    }

    private function loginAsAdmin(): void
    {
        $admin = (new User())
            ->setEmail(bin2hex(random_bytes(4)).'@agency-create-test.local')
            ->setFirstName('First')->setLastName('Last')
            ->setRoles(['ROLE_ADMIN'])->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($admin);
        $this->em->flush();

        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($admin, 'main', $admin->getRoles()));
    }
}
