<?php

declare(strict_types=1);

namespace App\Tests\RealEstateAgent;

use App\Auth\Entity\User;
use App\RealEstateAgent\Entity\Agency;
use App\RealEstateAgent\Entity\RealEstateAgent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * RealEstateAgent:AgencyCreate behaviour: standalone agency creation, the
 * unique-name guard (case-insensitive), blank rejection, and the modal
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
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@agency-create-test.local')->execute();
        $this->loginAsAdmin();
    }

    public function testCreatesAnAgencyAndRedirectsToTheList(): void
    {
        $component = $this->mountComponent();
        $component->formValues = ['name' => 'Foncia Paris 11'];

        $response = $this->createAction($component);
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertMatchesRegularExpression('~/'.self::PREFIX.'/admin/(agents-immobiliers|real-estate-agents)$~', (string) $response->getTargetUrl());

        $agency = $this->em->getRepository(Agency::class)->findOneBy(['name' => 'Foncia Paris 11']);
        self::assertNotNull($agency);
        self::assertNotNull($agency->getCreatedAt());
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

    public function testOpenModalMarkupIsAccessible(): void
    {
        $rendered = (string) $this->renderTwigComponent('RealEstateAgent:AgencyCreate', [
            'adminPrefix' => self::PREFIX,
            'open' => true,
        ]);

        self::assertStringContainsString('role="dialog"', $rendered);
        self::assertStringContainsString('aria-modal="true"', $rendered);
        self::assertStringContainsString('aria-labelledby="agency-create-title"', $rendered);
        self::assertStringContainsString('data-loading="action(create)|addAttribute(disabled)"', $rendered);
    }

    public function testTheTriggerClosesTheCreateDropdown(): void
    {
        // The menu-item trigger must also collapse the shared "Créer" dropdown.
        $rendered = (string) $this->renderTwigComponent('RealEstateAgent:AgencyCreate', ['adminPrefix' => self::PREFIX]);
        self::assertStringContainsString('data-action="live#action details-dropdown#close"', $rendered);
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
