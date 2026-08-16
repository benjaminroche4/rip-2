<?php

declare(strict_types=1);

namespace App\Tests\RealEstateAgent;

use App\Auth\Entity\User;
use App\RealEstateAgent\Domain\AgencyPosition;
use App\RealEstateAgent\Domain\AgentSpecialty;
use App\RealEstateAgent\Entity\Agency;
use App\RealEstateAgent\Entity\Brand;
use App\RealEstateAgent\Entity\RealEstateAgent;
use App\RealEstateAgent\Twig\Components\AgentDetails;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * RealEstateAgent:AgentDetails behaviour: inline edit (validation, agency
 * find-or-create, independent drops the position) and the guarded profile
 * deletion.
 */
final class AgentDetailsTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.RealEstateAgent::class)->execute();
        $this->em->createQuery('DELETE FROM '.Agency::class)->execute();
        $this->em->createQuery('DELETE FROM '.Brand::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@agent-details-test.local')->execute();
    }

    public function testSavePersistsIdentityContactAgencyAndChips(): void
    {
        $agent = $this->persistAgent('Jean', 'Martin');
        $this->loginWithRoles(['ROLE_ADMIN']);

        $component = $this->mount((int) $agent->getId());
        $component->startEditing();
        self::assertTrue($component->editing);

        $component->firstName = 'Jeanne';
        $component->lastName = 'Marchand';
        $component->email = 'jeanne@century.fr';
        $component->phone = '+33655667788';
        $component->agencyName = 'Century 21 Bastille';
        $component->note = 'Préfère les visites le matin.';
        $component->toggleSpecialty(AgentSpecialty::Location->value);
        $component->toggleCard(\App\RealEstateAgent\Domain\ProfessionalCard::Transaction->value);
        $component->toggleCard(\App\RealEstateAgent\Domain\ProfessionalCard::Gestion->value);
        // Toggle-off : recliquer une carte la retire.
        $component->toggleCard(\App\RealEstateAgent\Domain\ProfessionalCard::Gestion->value);
        $component->togglePosition(AgencyPosition::Manager->value);
        $component->saveDetails($this->em, self::getContainer()->get(TranslatorInterface::class));

        self::assertFalse($component->editing);
        $this->em->clear();
        $saved = $this->em->find(RealEstateAgent::class, $agent->getId());
        self::assertSame('Jeanne', $saved->getFirstName());
        self::assertSame('Marchand', $saved->getLastName());
        self::assertSame('jeanne@century.fr', $saved->getEmail());
        self::assertSame('+33655667788', $saved->getPhone());
        // A new agency name creates the agency (find-or-create).
        self::assertSame('Century 21 Bastille', $saved->getAgency()?->getName());
        self::assertSame([AgentSpecialty::Location], $saved->getSpecialties());
        self::assertSame([\App\RealEstateAgent\Domain\ProfessionalCard::Transaction], $saved->getProfessionalCards());
        self::assertSame(AgencyPosition::Manager, $saved->getPosition());
        self::assertSame('Préfère les visites le matin.', $saved->getNote());
        // The list card switches from "Ajouté le" to "Modifié le" on this date.
        self::assertNotNull($saved->getUpdatedAt());
    }

    public function testValidationBlocksSaveAndKeepsEditing(): void
    {
        $agent = $this->persistAgent('Jean', 'Martin');
        $this->loginWithRoles(['ROLE_ADMIN']);

        $component = $this->mount((int) $agent->getId());
        $component->startEditing();
        $component->firstName = '';
        $component->email = 'pas-un-email';
        $component->saveDetails($this->em, self::getContainer()->get(TranslatorInterface::class));

        self::assertTrue($component->editing);
        self::assertArrayHasKey('firstName', $component->errors);
        self::assertArrayHasKey('email', $component->errors);
        $this->em->clear();
        self::assertSame('Jean', $this->em->find(RealEstateAgent::class, $agent->getId())->getFirstName());
    }

    public function testIndependentAgentDropsThePosition(): void
    {
        $agent = $this->persistAgent('Jean', 'Martin', agency: 'Foncia Paris 11', position: AgencyPosition::Manager);
        $this->loginWithRoles(['ROLE_ADMIN']);

        $component = $this->mount((int) $agent->getId());
        $component->startEditing();
        $component->agencyName = '';
        $component->saveDetails($this->em, self::getContainer()->get(TranslatorInterface::class));

        $this->em->clear();
        $saved = $this->em->find(RealEstateAgent::class, $agent->getId());
        self::assertNull($saved->getAgency());
        self::assertNull($saved->getPosition());
    }

    public function testDeleteRemovesTheProfileAndRedirectsToTheList(): void
    {
        $agent = $this->persistAgent('Jean', 'Martin');
        $agentId = (int) $agent->getId();
        $this->loginWithRoles(['ROLE_ADMIN']);

        $component = $this->mount($agentId);
        $component->askDelete();
        self::assertTrue($component->confirmingDelete);

        $response = $component->deleteAgent($this->em);
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertStringContainsString('/agents-immobiliers', (string) $response->getTargetUrl());

        $this->em->clear();
        self::assertNull($this->em->find(RealEstateAgent::class, $agentId));
    }

    public function testActionsRequireTheAgentsSectionRole(): void
    {
        $agent = $this->persistAgent('Jean', 'Martin');
        $this->loginWithRoles(['ROLE_SECTION_CONTACTS']);

        $this->expectException(AccessDeniedException::class);
        $this->mount((int) $agent->getId());
    }

    private function mount(int $agentId): AgentDetails
    {
        /** @var AgentDetails $component */
        $component = $this->mountTwigComponent('RealEstateAgent:AgentDetails', [
            'agentId' => $agentId,
            'adminPrefix' => 'test_admin_prefix_1234567890abcdef',
        ]);

        return $component;
    }

    /**
     * @param list<string> $roles
     */
    private function loginWithRoles(array $roles): void
    {
        $user = (new User())
            ->setEmail(bin2hex(random_bytes(4)).'@agent-details-test.local')
            ->setFirstName('Test')->setLastName('Staff')
            ->setRoles($roles)->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken($user, 'main', $user->getRoles()),
        );
    }

    private function persistAgent(
        string $firstName,
        string $lastName,
        ?string $agency = null,
        ?AgencyPosition $position = null,
    ): RealEstateAgent {
        $agencyEntity = null;
        if (null !== $agency) {
            $agencyEntity = (new Agency())->setName($agency)->setCreatedAt(new \DateTimeImmutable());
            $this->em->persist($agencyEntity);
        }

        $agent = (new RealEstateAgent())
            ->setFirstName($firstName)
            ->setLastName($lastName)
            ->setAgency($agencyEntity)
            ->setPosition($position)
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($agent);
        $this->em->flush();

        return $agent;
    }
}
