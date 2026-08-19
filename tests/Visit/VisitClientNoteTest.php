<?php

declare(strict_types=1);

namespace App\Tests\Visit;

use App\Auth\Entity\User;
use App\Dossier\Entity\Dossier;
use App\Visit\Domain\VisitStatus;
use App\Visit\Entity\Visit;
use App\Visit\Service\VisitClientNoteGenerator;
use App\Visit\Service\VisitPropertyRecap;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Note envoyée au client après la visite : enregistrement manuel via le
 * POST dédié (CSRF + section), génération IA à la demande (persistée, ou
 * flash d'erreur douce quand le modèle est injoignable), et contexte du
 * prompt nourri par le retour interne sans jamais l'exposer tel quel.
 */
final class VisitClientNoteTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $adminPrefix;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->adminPrefix = (string) $container->getParameter('admin_path_prefix');
        $this->em = $container->get('doctrine.orm.entity_manager');

        $this->em->createQuery('DELETE FROM '.Visit::class)->execute();
        $this->em->createQuery('DELETE FROM '.Dossier::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@visit-client-note-test.local')->execute();
    }

    public function testItPersistsAHandWrittenNote(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        $visit = $this->persistVisit();

        $this->client->request(
            'POST',
            $this->visitUrl($visit).'/note-client',
            ['_token' => $this->validToken($visit), 'clientNote' => 'Merci pour votre visite, voici la suite.'],
        );

        self::assertResponseStatusCodeSame(303);
        $this->em->clear();
        self::assertSame('Merci pour votre visite, voici la suite.', $this->em->find(Visit::class, $visit->getId())->getClientNote());
    }

    public function testItRejectsAnInvalidCsrfToken(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        $visit = $this->persistVisit();

        $this->client->request(
            'POST',
            $this->visitUrl($visit).'/note-client',
            ['_token' => 'not-a-valid-token', 'clientNote' => 'X'],
        );

        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertNull($this->em->find(Visit::class, $visit->getId())->getClientNote());
    }

    public function testItRefusesTheNoteWithoutTheVisitsSection(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_CONTACTS']);
        $visit = $this->persistVisit();

        $this->client->request(
            'POST',
            $this->visitUrl($visit).'/note-client',
            ['_token' => 'irrelevant', 'clientNote' => 'X'],
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testTheGeneratorDraftsFromThePropertyAndInternalFeedback(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $container = self::getContainer();
        $visit = (new Visit())
            ->setReference('VS-'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT))
            ->setScheduledAt(new \DateTimeImmutable('2026-08-18 11:00'))
            ->setAddress('12 rue de la Roquette, 75011 Paris')
            ->setReport('Client emballé par le séjour, réserve sur le vis-à-vis.')
            ->setCreatedAt(new \DateTimeImmutable());

        $captured = null;
        $agent = new class($captured) implements AgentInterface {
            public ?string $prompt = null;

            public function __construct(private mixed $unused)
            {
            }

            public function call(string|MessageBag|UserMessage $input, array $options = []): ResultInterface
            {
                \assert($input instanceof MessageBag);
                foreach ($input->getMessages() as $message) {
                    if ($message instanceof UserMessage) {
                        $this->prompt = implode('', array_map(static fn ($c) => $c->getText(), $message->getContent()));
                    }
                }

                return new TextResult('Bonjour, merci pour votre visite rue de la Roquette.');
            }

            public function getName(): string
            {
                return 'fixed';
            }
        };
        $generator = new VisitClientNoteGenerator($agent, $container->get(VisitPropertyRecap::class), new NullLogger());

        $note = $generator->generate($visit);

        self::assertSame('Bonjour, merci pour votre visite rue de la Roquette.', $note);
        self::assertStringContainsString('12 rue de la Roquette', (string) $agent->prompt);
        self::assertStringContainsString('Client emballé par le séjour', (string) $agent->prompt, 'The internal feedback feeds the prompt.');
    }

    public function testAModelFailureReturnsNull(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $agent = new class implements AgentInterface {
            public function call(string|MessageBag|UserMessage $input, array $options = []): ResultInterface
            {
                throw new \RuntimeException('API unreachable');
            }

            public function getName(): string
            {
                return 'failing';
            }
        };
        $generator = new VisitClientNoteGenerator($agent, self::getContainer()->get(VisitPropertyRecap::class), new NullLogger());

        $visit = (new Visit())
            ->setReference('VS-000001')
            ->setScheduledAt(new \DateTimeImmutable())
            ->setAddress('X')
            ->setCreatedAt(new \DateTimeImmutable());

        self::assertNull($generator->generate($visit));
    }

    public function testAFilledNoteRendersLockedWithAPencilAndTheFormStaysInTheDom(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        $visit = $this->persistVisit();
        $visit->setClientNote('Merci pour votre visite, voici la suite.');
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->visitUrl($visit));

        self::assertResponseIsSuccessful();
        $display = $crawler->filter('[data-testid="visit-client-note-display"]');
        self::assertCount(1, $display);
        self::assertStringContainsString('voici la suite', $display->text());
        self::assertCount(1, $crawler->filter('[data-testid="visit-client-note-edit"]'));
        // L'éditeur reste dans le DOM (autosave + tests crawler), juste masqué.
        $textarea = $crawler->filter('[data-testid="visit-client-note-text"]');
        self::assertCount(1, $textarea);
        self::assertStringContainsString('hidden', (string) $textarea->attr('class'));
        self::assertCount(1, $crawler->filter('[data-testid="visit-client-note-form"] textarea[name="clientNote"]'));
    }

    public function testAnEmptyNoteRendersDirectlyInEditMode(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        $visit = $this->persistVisit();

        $crawler = $this->client->request('GET', $this->visitUrl($visit));

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="visit-client-note-display"]'));
        self::assertCount(0, $crawler->filter('[data-testid="visit-client-note-edit"]'));
        $textarea = $crawler->filter('[data-testid="visit-client-note-text"]');
        self::assertCount(1, $textarea);
        self::assertStringNotContainsString('hidden', (string) $textarea->attr('class'));
    }

    private function validToken(Visit $visit): string
    {
        $crawler = $this->client->request('GET', $this->visitUrl($visit));
        self::assertResponseIsSuccessful();

        return (string) $crawler->filter('[data-testid="visit-client-note-form"] input[name="_token"]')->attr('value');
    }

    private function visitUrl(Visit $visit): string
    {
        return '/fr/'.$this->adminPrefix.'/admin/visites/'.$visit->getReference();
    }

    private function persistVisit(): Visit
    {
        $dossier = (new Dossier())
            ->setName('Famille Martin')
            ->setReference('DS-'.random_int(100000, 999999))
            ->setPairingCode(substr(strtoupper(bin2hex(random_bytes(4))), 0, 6))
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($dossier);

        $visit = (new Visit())
            ->setReference('VS-'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT))
            ->setDossier($dossier)
            ->setScheduledAt(new \DateTimeImmutable('-1 day 10:30'))
            ->setStatus(VisitStatus::Done)
            ->setAddress('12 rue de la Roquette, 75011 Paris')
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($visit);
        $this->em->flush();

        return $visit;
    }

    private function loginAs(array $roles): void
    {
        $user = (new User())
            ->setEmail(bin2hex(random_bytes(4)).'@visit-client-note-test.local')
            ->setFirstName('First')->setLastName('Last')
            ->setRoles($roles)->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);
    }
}
