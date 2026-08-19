<?php

declare(strict_types=1);

namespace App\Tests\Visit;

use App\Auth\Entity\User;
use App\Dossier\Domain\ContactLanguage;
use App\Dossier\Domain\DossierPersonRole;
use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierPerson;
use App\Visit\Domain\PropertyHighlight;
use App\Visit\Domain\VisitStatus;
use App\Visit\Entity\Visit;
use App\Visit\Service\VisitClientNoteGenerator;
use App\Visit\Service\VisitPropertyRecap;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Tags rapides "Les plus du logement" du compte-rendu : chips multi
 * toggle-off à autosave sur le POST du report, valeurs validées serveur
 * (jamais de valeur forgée en base), rendues dans l'email de note client
 * et injectées dans le prompt de génération IA.
 */
final class VisitReportHighlightsTest extends WebTestCase
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
        $this->em->createQuery('DELETE FROM '.DossierPerson::class)->execute();
        $this->em->createQuery('DELETE FROM '.Dossier::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@visit-highlights-test.local')->execute();
    }

    public function testItPersistsTheTickedHighlightsInStableEnumOrder(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        $visit = $this->persistVisit();

        // Cochés dans le désordre : le stockage suit l'ordre de l'enum.
        $this->client->request('POST', $this->visitUrl($visit).'/compte-rendu', [
            '_token' => $this->reportToken($visit),
            'report' => 'Bel appartement.',
            'feeling' => 'hot',
            'highlights' => ['quiet', 'bright', 'outdoor'],
        ]);

        self::assertResponseStatusCodeSame(303);
        $this->em->clear();
        $reloaded = $this->em->find(Visit::class, $visit->getId());
        self::assertSame(
            [PropertyHighlight::Bright, PropertyHighlight::Quiet, PropertyHighlight::Outdoor],
            $reloaded->getReportHighlights(),
        );

        // Les chips actives se rendent cochées sur la fiche.
        $crawler = $this->client->request('GET', $this->visitUrl($visit));
        self::assertResponseIsSuccessful();
        self::assertCount(\count(PropertyHighlight::cases()), $crawler->filter('[data-testid="visit-highlights"] input[name="highlights[]"]'));
        self::assertNotNull($crawler->filter('[data-testid="visit-highlight-bright"]')->attr('checked'));
        self::assertNull($crawler->filter('[data-testid="visit-highlight-spacious"]')->attr('checked'));
    }

    public function testTogglingEverythingOffClearsTheColumn(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        $visit = $this->persistVisit();
        $visit->setReportHighlights([PropertyHighlight::Bright]);
        $this->em->flush();

        // Un POST sans le champ (toutes les chips décochées) purge le tout.
        $this->client->request('POST', $this->visitUrl($visit).'/compte-rendu', [
            '_token' => $this->reportToken($visit),
            'report' => 'Bel appartement.',
        ]);

        self::assertResponseStatusCodeSame(303);
        $this->em->clear();
        self::assertSame([], $this->em->find(Visit::class, $visit->getId())->getReportHighlights());
    }

    public function testAForgedHighlightValueIsRejected(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        $visit = $this->persistVisit();

        $this->client->request('POST', $this->visitUrl($visit).'/compte-rendu', [
            '_token' => $this->reportToken($visit),
            'report' => 'X',
            'highlights' => ['bright', 'swimming_pool'],
        ]);

        self::assertResponseStatusCodeSame(400);
        $this->em->clear();
        self::assertSame([], $this->em->find(Visit::class, $visit->getId())->getReportHighlights(), 'A forged value never lands in the column.');
    }

    public function testTheHighlightsRenderInTheClientNoteEmail(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        $visit = $this->persistVisit(withPerson: true);
        $visit->setClientNote('Nous vous recommandons ce bien.')
            ->setReportHighlights([PropertyHighlight::Bright, PropertyHighlight::Quiet]);
        $this->em->flush();

        $this->client->request('POST', $this->visitUrl($visit).'/note-client/envoyer', [
            '_token' => $this->noteToken($visit),
        ]);

        self::assertResponseStatusCodeSame(303);
        self::assertEmailCount(1);
        $body = (string) self::getMailerMessages()[0]->getHtmlBody();
        self::assertStringContainsString('Les plus du logement', $body);
        self::assertStringContainsString('lumineux, calme', $body);
    }

    public function testTheHighlightsFeedTheAiGenerationPrompt(): void
    {
        $visit = $this->persistVisit();
        $visit->setReportHighlights([PropertyHighlight::Bright, PropertyHighlight::NotOverlooked]);
        $this->em->flush();

        $captured = new \stdClass();
        $agent = new class($captured) implements AgentInterface {
            public function __construct(private readonly \stdClass $captured)
            {
            }

            public function call(string|MessageBag|UserMessage $input, array $options = []): ResultInterface
            {
                $this->captured->input = $input;

                return new TextResult('Note générée.');
            }

            public function getName(): string
            {
                return 'stub';
            }
        };
        $container = static::getContainer();
        $generator = new VisitClientNoteGenerator(
            $agent,
            $container->get(VisitPropertyRecap::class),
            new NullLogger(),
            $container->get('translator'),
        );

        self::assertSame('Note générée.', $generator->generate($visit));

        \assert($captured->input instanceof MessageBag);
        $userMessage = $captured->input->getUserMessage();
        self::assertNotNull($userMessage);
        $text = implode("\n", array_map(
            static fn (Text $part): string => $part->getText(),
            array_filter($userMessage->getContent(), static fn ($part): bool => $part instanceof Text),
        ));
        self::assertStringContainsString('Les plus du logement cochés par le conseiller : Lumineux, Sans vis-à-vis', $text);
    }

    private function reportToken(Visit $visit): string
    {
        $crawler = $this->client->request('GET', $this->visitUrl($visit));
        self::assertResponseIsSuccessful();

        return (string) $crawler->filter('[data-testid="visit-report-form"] input[name="_token"]')->attr('value');
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

    private function persistVisit(bool $withPerson = false): Visit
    {
        $dossier = (new Dossier())
            ->setName('Famille Martin')
            ->setReference('DS-'.random_int(100000, 999999))
            ->setPairingCode(substr(strtoupper(bin2hex(random_bytes(4))), 0, 6))
            ->setCreatedAt(new \DateTimeImmutable());
        if ($withPerson) {
            $dossier->addPerson((new DossierPerson())
                ->setRole(DossierPersonRole::TENANT)
                ->setFirstName('Jean')->setLastName('Martin')
                ->setEmail('jean@visit-highlights-test.local')
                ->setLanguage(ContactLanguage::FR)
                ->setPrimaryContact(true));
        }
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
            ->setEmail(bin2hex(random_bytes(4)).'@visit-highlights-test.local')
            ->setFirstName('First')->setLastName('Last')
            ->setRoles($roles)->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);
    }
}
