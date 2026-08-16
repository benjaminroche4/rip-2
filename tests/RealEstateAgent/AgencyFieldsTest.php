<?php

declare(strict_types=1);

namespace App\Tests\RealEstateAgent;

use App\Auth\Entity\User;
use App\RealEstateAgent\Domain\AgentSpecialty;
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
 * New agency profile fields: the team-only note, the website (normalised to
 * https://) and the specialties chips, on the creation form.
 */
final class AgencyFieldsTest extends KernelTestCase
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
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@agency-fields-test.local')->execute();
        $this->loginAsAdmin();
    }

    public function testStoresNoteWebsiteAndSpecialties(): void
    {
        $component = $this->mountComponent();
        $component->formValues = [
            'name' => 'Foncia Paris 11',
            'brandName' => '',
            'address' => '',
            'phone' => '',
            'email' => '',
            'website' => 'https://www.foncia.fr',
            'specialties' => [AgentSpecialty::Location->value, AgentSpecialty::Transaction->value],
            'note' => 'Exigent un dossier complet avant visite.',
        ];

        self::assertInstanceOf(RedirectResponse::class, $this->createAction($component));

        $agency = $this->em->getRepository(Agency::class)->findOneBy(['name' => 'Foncia Paris 11']);
        self::assertNotNull($agency);
        self::assertSame('https://www.foncia.fr', $agency->getWebsite());
        self::assertSame([AgentSpecialty::Location, AgentSpecialty::Transaction], $agency->getSpecialties());
        self::assertSame('Exigent un dossier complet avant visite.', $agency->getNote());
    }

    public function testWebsiteWithoutSchemeIsPrefixedWithHttps(): void
    {
        $component = $this->mountComponent();
        $component->formValues = $this->values(['website' => 'www.foncia.fr']);

        self::assertInstanceOf(RedirectResponse::class, $this->createAction($component));

        $agency = $this->em->getRepository(Agency::class)->findOneBy(['name' => 'Foncia Paris 11']);
        self::assertSame('https://www.foncia.fr', $agency?->getWebsite());
    }

    public function testInvalidWebsiteBlocksCreation(): void
    {
        $component = $this->mountComponent();
        $component->formValues = $this->values(['website' => 'not a website']);

        try {
            $this->createAction($component);
            self::fail('Expected an unprocessable-entity exception.');
        } catch (HttpExceptionInterface $e) {
            self::assertSame(422, $e->getStatusCode());
        }

        self::assertSame(0, (int) $this->em->getRepository(Agency::class)->count([]));
    }

    public function testBlankNoteWebsiteAndSpecialtiesAreStoredAsNull(): void
    {
        $component = $this->mountComponent();
        $component->formValues = $this->values(['website' => '   ', 'note' => '   ', 'specialties' => []]);

        self::assertInstanceOf(RedirectResponse::class, $this->createAction($component));

        $agency = $this->em->getRepository(Agency::class)->findOneBy(['name' => 'Foncia Paris 11']);
        self::assertNotNull($agency);
        self::assertNull($agency->getWebsite());
        self::assertNull($agency->getNote());
        self::assertSame([], $agency->getSpecialties());
    }

    public function testNoteOverTwoThousandCharactersBlocksCreation(): void
    {
        $component = $this->mountComponent();
        $component->formValues = $this->values(['note' => str_repeat('a', 2001)]);

        try {
            $this->createAction($component);
            self::fail('Expected an unprocessable-entity exception.');
        } catch (HttpExceptionInterface $e) {
            self::assertSame(422, $e->getStatusCode());
        }

        self::assertSame(0, (int) $this->em->getRepository(Agency::class)->count([]));
    }

    public function testCreateFormRendersTheNewFields(): void
    {
        $rendered = (string) $this->renderTwigComponent('RealEstateAgent:AgencyCreate', [
            'adminPrefix' => self::PREFIX,
        ]);

        self::assertStringContainsString('data-testid="agency-create-website"', $rendered);
        self::assertStringContainsString('data-testid="agency-create-note"', $rendered);
        // Same specialty chips as the agent form, multi with toggle-off.
        self::assertStringContainsString('data-testid="agency-create-specialty-location"', $rendered);
        self::assertStringContainsString('data-testid="agency-create-specialty-transaction"', $rendered);
        // The internal note stays team-only, with the hint below the field.
        self::assertStringContainsString('Visible uniquement par l', $rendered);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function values(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Foncia Paris 11',
            'brandName' => '',
            'address' => '',
            'phone' => '',
            'email' => '',
            'website' => '',
            'specialties' => [],
            'note' => '',
        ], $overrides);
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
            ->setEmail(bin2hex(random_bytes(4)).'@agency-fields-test.local')
            ->setFirstName('First')->setLastName('Last')
            ->setRoles(['ROLE_ADMIN'])->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($admin);
        $this->em->flush();

        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($admin, 'main', $admin->getRoles()));
    }
}
