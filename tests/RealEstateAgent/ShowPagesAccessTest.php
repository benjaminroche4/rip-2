<?php

declare(strict_types=1);

namespace App\Tests\RealEstateAgent;

use App\Auth\Entity\User;
use App\RealEstateAgent\Domain\AgencyPosition;
use App\RealEstateAgent\Domain\AgentSpecialty;
use App\RealEstateAgent\Entity\Agency;
use App\RealEstateAgent\Entity\Brand;
use App\RealEstateAgent\Entity\RealEstateAgent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Access contract and content of the agent / agency detail pages:
 *   1. wrong prefix → 404 before any auth challenge
 *   2. anonymous on the real path → login redirect
 *   3. staff without ROLE_SECTION_AGENTS → 403
 *   4. staff with the section role → 200, identity + contact rendered
 *   5. unknown id → 404, list rows link to the pages
 */
final class ShowPagesAccessTest extends WebTestCase
{
    private const EMAIL_DOMAIN = '@agent-show-test.local';

    private KernelBrowser $client;
    private string $adminPrefix;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();

        $this->adminPrefix = (string) $container->getParameter('admin_path_prefix');
        $this->em = $container->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.RealEstateAgent::class)->execute();
        $this->em->createQuery('DELETE FROM '.Agency::class)->execute();
        $this->em->createQuery('DELETE FROM '.Brand::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')
            ->setParameter('p', '%'.self::EMAIL_DOMAIN)->execute();
    }

    public function testWrongPrefixReturns404BeforeAnyAuthChallenge(): void
    {
        $agent = $this->persistAgent('Jean', 'Martin');

        foreach (['/agents-immobiliers/'.$agent->getId(), '/agents-immobiliers/agences/'.$agent->getAgency()->getId()] as $path) {
            $this->client->request('GET', '/fr/00000000000000000000000000000000/admin'.$path);
            self::assertResponseStatusCodeSame(404);
        }
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $agent = $this->persistAgent('Jean', 'Martin');

        $this->client->request('GET', $this->agentUrl((int) $agent->getId()));
        self::assertResponseRedirects();
        self::assertStringContainsString('/connexion', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testStaffWithoutTheSectionRoleGets403(): void
    {
        $agent = $this->persistAgent('Jean', 'Martin');
        $this->loginWithRoles(['ROLE_SECTION_CONTACTS']);

        $this->client->request('GET', $this->agentUrl((int) $agent->getId()));
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', $this->agencyUrl((int) $agent->getAgency()->getId()));
        self::assertResponseStatusCodeSame(403);
    }

    public function testAgentPageRendersIdentityContactAndNote(): void
    {
        $agent = $this->persistAgent(
            'Jean',
            'Martin',
            email: 'jean@foncia.fr',
            phone: '+33611223344',
            specialties: [AgentSpecialty::Location],
            position: AgencyPosition::ConsultantRental,
            note: 'Réactif sur WhatsApp.',
        );
        $this->loginWithRoles(['ROLE_SECTION_AGENTS']);

        $crawler = $this->client->request('GET', $this->agentUrl((int) $agent->getId()));
        self::assertResponseIsSuccessful();

        $page = $crawler->filter('[data-testid="agent-show-page"]')->text();
        self::assertStringContainsString('Jean Martin', $page);
        self::assertStringContainsString('jean@foncia.fr', $page);
        // Formatted like on the lead page (flag + international spacing).
        self::assertStringContainsString('+33 6 11 22 33 44', $page);
        self::assertSame(1, $crawler->filter('[data-testid="agent-show-phone"] svg')->count(), 'The fr flag icon renders next to the number.');
        self::assertStringContainsString('Réactif sur WhatsApp.', $page);
        // The agency pair links to the agency page.
        $href = (string) $crawler->filter('[data-testid="agent-show-agency-link"]')->attr('href');
        self::assertStringContainsString('/agents-immobiliers/agences/'.$agent->getAgency()->getId(), $href);
    }

    public function testAgencyPageRendersIdentityAndItsAgents(): void
    {
        $agent = $this->persistAgent('Jean', 'Martin', position: AgencyPosition::Manager);
        $agency = $agent->getAgency();
        $agency->setAddress('12 rue de la Paix, 75002 Paris')->setPhone('+33144556677')->setEmail('contact@foncia.fr');
        $this->em->flush();
        $this->loginWithRoles(['ROLE_SECTION_AGENTS']);

        $crawler = $this->client->request('GET', $this->agencyUrl((int) $agency->getId()));
        self::assertResponseIsSuccessful();

        $page = $crawler->filter('[data-testid="agency-show-page"]')->text();
        self::assertStringContainsString('Foncia Paris 11', $page);
        self::assertStringContainsString('12 rue de la Paix, 75002 Paris', $page);
        self::assertStringContainsString('contact@foncia.fr', $page);
        self::assertStringContainsString('Jean Martin', $page);
        // The agent row links back to the agent page.
        $href = (string) $crawler->filter('[data-testid="agency-agent-row"] a')->attr('href');
        self::assertStringContainsString('/agents-immobiliers/'.$agent->getId(), $href);
    }

    public function testAgencyPageWithoutAgentsShowsTheEmptyMessage(): void
    {
        $agency = (new Agency())->setName('Agence Vide')->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($agency);
        $this->em->flush();
        $this->loginWithRoles(['ROLE_SECTION_AGENTS']);

        $this->client->request('GET', $this->agencyUrl((int) $agency->getId()));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="agency-agents-empty"]');
    }

    public function testUnknownIdsReturn404(): void
    {
        $this->loginWithRoles(['ROLE_SECTION_AGENTS']);

        $this->client->request('GET', $this->agentUrl(999999));
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', $this->agencyUrl(999999));
        self::assertResponseStatusCodeSame(404);
    }

    public function testListRowsLinkToTheDetailPages(): void
    {
        $agent = $this->persistAgent('Jean', 'Martin');
        $this->loginWithRoles(['ROLE_SECTION_AGENTS']);

        $crawler = $this->client->request('GET', '/fr/'.$this->adminPrefix.'/admin/agents-immobiliers');
        self::assertResponseIsSuccessful();

        $agentHref = (string) $crawler->filter('[data-testid="agent-row-link"]')->attr('href');
        self::assertStringContainsString('/agents-immobiliers/'.$agent->getId(), $agentHref);
    }

    private function agentUrl(int $id): string
    {
        return '/fr/'.$this->adminPrefix.'/admin/agents-immobiliers/'.$id;
    }

    private function agencyUrl(int $id): string
    {
        return '/fr/'.$this->adminPrefix.'/admin/agents-immobiliers/agences/'.$id;
    }

    /**
     * @param list<string> $roles
     */
    private function loginWithRoles(array $roles): void
    {
        $user = (new User())
            ->setEmail(bin2hex(random_bytes(4)).self::EMAIL_DOMAIN)
            ->setFirstName('Test')
            ->setLastName('Staff')
            ->setRoles($roles)
            ->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)
            ->setVerified(true);

        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);
    }

    /**
     * @param list<AgentSpecialty> $specialties
     */
    private function persistAgent(
        string $firstName,
        string $lastName,
        ?string $email = null,
        ?string $phone = null,
        array $specialties = [],
        ?AgencyPosition $position = null,
        ?string $note = null,
    ): RealEstateAgent {
        $agency = $this->em->getRepository(Agency::class)->findOneBy(['name' => 'Foncia Paris 11'])
            ?? (new Agency())->setName('Foncia Paris 11')->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($agency);

        $agent = (new RealEstateAgent())
            ->setFirstName($firstName)
            ->setLastName($lastName)
            ->setAgency($agency)
            ->setEmail($email)
            ->setPhone($phone)
            ->setSpecialties($specialties)
            ->setPosition($position)
            ->setNote($note)
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($agent);
        $this->em->flush();

        return $agent;
    }
}
