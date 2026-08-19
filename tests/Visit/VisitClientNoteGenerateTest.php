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
 * Contrat HTTP de la route de génération IA de la note client
 * (POST /visites/{ref}/note-client/generer) : succès persisté, CSRF
 * obligatoire, rôle de section requis, préfixe caché en 404, et échec du
 * modèle rendu en flash doux sur la fiche (jamais un 500).
 */
final class VisitClientNoteGenerateTest extends WebTestCase
{
    private const WRONG_PREFIX = '00000000000000000000000000000000';

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
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@visit-note-generate-test.local')->execute();
    }

    public function testASuccessfulGenerationPersistsTheDraftAndRedirects(): void
    {
        // Kernel conservé entre les requêtes : le stub posé dans le
        // conteneur doit survivre jusqu'au POST (pattern VisitPhotoTest).
        $this->client->disableReboot();
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        $visit = $this->persistVisit();
        $token = $this->noteToken($visit);
        $this->stubGenerator(new TextResult('Bonjour, merci pour votre visite rue de la Roquette.'));

        $this->client->request('POST', $this->visitUrl($visit).'/note-client/generer', [
            '_token' => $token,
        ]);

        self::assertResponseStatusCodeSame(303);
        $this->em->clear();
        self::assertSame(
            'Bonjour, merci pour votre visite rue de la Roquette.',
            $this->em->find(Visit::class, $visit->getId())->getClientNote(),
        );
    }

    public function testAModelFailureRendersTheSoftErrorFlashOnThePage(): void
    {
        $this->client->disableReboot();
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        $visit = $this->persistVisit();
        $token = $this->noteToken($visit);
        $this->stubGenerator(null);

        $this->client->request('POST', $this->visitUrl($visit).'/note-client/generer', [
            '_token' => $token,
        ]);

        self::assertResponseStatusCodeSame(303);
        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="visit-client-note-error"]'), 'The soft error flash renders on the visit page.');

        $this->em->clear();
        self::assertNull($this->em->find(Visit::class, $visit->getId())->getClientNote(), 'A failed generation never touches the note.');
    }

    public function testItRejectsAnInvalidCsrfToken(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        $visit = $this->persistVisit();

        $this->client->request('POST', $this->visitUrl($visit).'/note-client/generer', [
            '_token' => 'not-a-valid-token',
        ]);

        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertNull($this->em->find(Visit::class, $visit->getId())->getClientNote());
    }

    public function testItRefusesTheGenerationWithoutTheVisitsSection(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_CONTACTS']);
        $visit = $this->persistVisit();

        $this->client->request('POST', $this->visitUrl($visit).'/note-client/generer', [
            '_token' => 'irrelevant',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testItReturns404ForAnAnonymousPostOnAWrongPrefix(): void
    {
        $visit = $this->persistVisit();

        $this->client->request(
            'POST',
            '/fr/'.self::WRONG_PREFIX.'/admin/visites/'.$visit->getReference().'/note-client/generer',
            ['_token' => 'x'],
        );

        // Mauvais préfixe : 404 avant tout challenge d'authentification.
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Remplace le générateur par une version au comportement fixe : un
     * résultat donné, ou l'échec modèle (null retourné par le service, qui
     * avale l'exception de l'agent).
     */
    private function stubGenerator(?TextResult $result): void
    {
        $agent = new class($result) implements AgentInterface {
            public function __construct(private readonly ?TextResult $result)
            {
            }

            public function call(string|MessageBag|UserMessage $input, array $options = []): ResultInterface
            {
                return $this->result ?? throw new \RuntimeException('API unreachable');
            }

            public function getName(): string
            {
                return 'stub';
            }
        };

        static::getContainer()->set(VisitClientNoteGenerator::class, new VisitClientNoteGenerator(
            $agent,
            static::getContainer()->get(VisitPropertyRecap::class),
            new NullLogger(),
        ));
    }

    private function noteToken(Visit $visit): string
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

    /**
     * @param list<string> $roles
     */
    private function loginAs(array $roles): void
    {
        $user = (new User())
            ->setEmail(bin2hex(random_bytes(4)).'@visit-note-generate-test.local')
            ->setFirstName('First')->setLastName('Last')
            ->setRoles($roles)->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);
    }
}
