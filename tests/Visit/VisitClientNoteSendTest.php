<?php

declare(strict_types=1);

namespace App\Tests\Visit;

use App\Auth\Entity\User;
use App\Dossier\Domain\ContactLanguage;
use App\Dossier\Domain\DossierPersonRole;
use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierEvent;
use App\Dossier\Entity\DossierPerson;
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
 * Envoi de la note client par email : toutes les personnes du dossier avec
 * un email valide la reçoivent (chacune dans sa langue, adresses
 * dédupliquées), l'horodatage d'envoi est persisté et affiché, la note
 * vide est refusée, et l'entrée part dans le fil du dossier.
 */
final class VisitClientNoteSendTest extends WebTestCase
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
        $this->em->createQuery('DELETE FROM '.DossierEvent::class)->execute();
        $this->em->createQuery('DELETE FROM '.DossierPerson::class)->execute();
        $this->em->createQuery('DELETE FROM '.Dossier::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@visit-note-send-test.local')->execute();
    }

    public function testItEmailsEveryReachableContactInTheirLanguageAndStampsTheVisit(): void
    {
        // Kernel conservé entre les requêtes : le stub de traduction posé
        // dans le conteneur doit survivre jusqu'au POST.
        $this->client->disableReboot();
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        $visit = $this->persistVisit(withPersons: true);
        $visit->setClientNote('Bel appartement lumineux, nous recommandons de vous positionner.');
        $this->em->flush();
        $token = $this->noteToken($visit);
        $this->installTranslationStub($this->translationAgent());

        $this->client->request('POST', $this->visitUrl($visit).'/note-client/envoyer', [
            '_token' => $token,
        ]);

        self::assertResponseStatusCodeSame(303);
        // Trois personnes, dont un doublon d'adresse et un email invalide :
        // deux emails seulement partent.
        self::assertEmailCount(2);
        $subjects = array_map(static fn ($m) => (string) $m->getSubject(), self::getMailerMessages());
        // getMailerMessages() liste chaque email deux fois (mis en file
        // Messenger puis envoyé en sync) : on dédoublonne les sujets.
        $subjects = array_values(array_unique($subjects));
        sort($subjects);
        self::assertCount(2, $subjects);
        self::assertStringStartsWith('Following up on your visit', $subjects[0]);
        self::assertStringStartsWith('Le point sur votre visite', $subjects[1]);
        // Chaque destinataire reçoit le corps dans sa langue : l'original
        // français pour Jean, la traduction (stub) pour Emma.
        $frBody = $this->bodyOfMessageWithSubjectStarting('Le point sur votre visite');
        self::assertStringContainsString('Bel appartement lumineux', $frBody);
        self::assertStringContainsString('12 rue de la Roquette', $frBody);
        $enBody = $this->bodyOfMessageWithSubjectStarting('Following up on your visit');
        self::assertStringContainsString('Translated: Bel appartement lumineux', $enBody);

        $this->em->clear();
        // La traduction est persistée sur la visite (traçabilité).
        self::assertSame(
            'Translated: Bel appartement lumineux, nous recommandons de vous positionner.',
            $this->em->find(Visit::class, $visit->getId())->getClientNoteEn(),
        );
        $reloaded = $this->em->find(Visit::class, $visit->getId());
        self::assertNotNull($reloaded->getClientNoteSentAt(), 'The send timestamp is persisted.');
        self::assertSame(1, $this->em->getRepository(DossierEvent::class)->count(['kind' => 'visit_client_note_sent']));

        // L'état "Envoyée le" s'affiche discrètement dans le bloc.
        $crawler = $this->client->request('GET', $this->visitUrl($visit));
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="visit-client-note-sent-at"]'));
    }

    public function testTheTimestampSurvivesALaterNoteEdit(): void
    {
        $this->client->disableReboot();
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        $visit = $this->persistVisit(withPersons: true);
        $visit->setClientNote('Première version.');
        $this->em->flush();
        $this->installTranslationStub($this->translationAgent());

        $this->client->request('POST', $this->visitUrl($visit).'/note-client/envoyer', ['_token' => $this->noteToken($visit)]);
        self::assertResponseStatusCodeSame(303);

        $this->client->request('POST', $this->visitUrl($visit).'/note-client', [
            '_token' => $this->noteToken($visit),
            'clientNote' => 'Version corrigée après envoi.',
        ]);
        self::assertResponseStatusCodeSame(303);

        $this->em->clear();
        $reloaded = $this->em->find(Visit::class, $visit->getId());
        self::assertSame('Version corrigée après envoi.', $reloaded->getClientNote());
        self::assertNotNull($reloaded->getClientNoteSentAt(), 'Editing the note never resets the sent state.');
    }

    public function testAnEmptyNoteIsRefused(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        $visit = $this->persistVisit(withPersons: true);

        $this->client->request('POST', $this->visitUrl($visit).'/note-client/envoyer', [
            '_token' => $this->noteToken($visit),
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertEmailCount(0);
        $this->em->clear();
        self::assertNull($this->em->find(Visit::class, $visit->getId())->getClientNoteSentAt());
    }

    public function testNoReachableContactLeavesTheVisitUnstamped(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        $visit = $this->persistVisit(withPersons: false);
        $visit->setClientNote('Une note sans destinataire.');
        $this->em->flush();

        $this->client->request('POST', $this->visitUrl($visit).'/note-client/envoyer', [
            '_token' => $this->noteToken($visit),
        ]);

        self::assertResponseStatusCodeSame(303);
        self::assertEmailCount(0);
        $this->em->clear();
        self::assertNull($this->em->find(Visit::class, $visit->getId())->getClientNoteSentAt());
        self::assertSame(0, $this->em->getRepository(DossierEvent::class)->count(['kind' => 'visit_client_note_sent']));
    }

    public function testAPartialTransportFailureIsReportedAsSuch(): void
    {
        $visit = $this->persistVisit(withPersons: true);
        $visit->setClientNote('Bel appartement, positionnez-vous vite.');
        $this->em->flush();

        // Transport qui tombe pour la destinataire anglophone seulement :
        // le service doit dire 1 envoyé sur 2 joignables (le contrôleur en
        // fait le toast "Note envoyée à N contacts sur M").
        $mailer = new class implements \Symfony\Component\Mailer\MailerInterface {
            public function send(\Symfony\Component\Mime\RawMessage $message, ?\Symfony\Component\Mailer\Envelope $envelope = null): void
            {
                \assert($message instanceof \Symfony\Component\Mime\Email);
                if (str_starts_with((string) $message->getTo()[0]->getAddress(), 'emma@')) {
                    throw new \RuntimeException('SMTP down for this one.');
                }
            }
        };
        $container = static::getContainer();
        $service = new \App\Visit\Service\VisitClientMailer($mailer, $container->get('translator'), $container->get('logger'), $container->get(\App\Visit\Storage\VisitPhotoStorage::class), $this->stubbedGenerator($this->translationAgent()));

        self::assertSame(['sent' => 1, 'total' => 2], $service->sendClientNote($visit));
    }

    public function testAFailedTranslationFallsBackToFrenchWithoutBlockingTheSend(): void
    {
        $this->client->disableReboot();
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        $visit = $this->persistVisit(withPersons: true);
        $visit->setClientNote('Bel appartement lumineux, positionnez-vous vite.');
        $this->em->flush();
        $token = $this->noteToken($visit);
        $this->installTranslationStub($this->translationAgent(failing: true));

        $this->client->request('POST', $this->visitUrl($visit).'/note-client/envoyer', [
            '_token' => $token,
        ]);

        // L'envoi part quand même (repli silencieux, jamais un 500) :
        // l'anglophone reçoit le texte français.
        self::assertResponseStatusCodeSame(303);
        self::assertEmailCount(2);
        $enBody = $this->bodyOfMessageWithSubjectStarting('Following up on your visit');
        self::assertStringContainsString('Bel appartement lumineux', $enBody);

        $this->em->clear();
        $reloaded = $this->em->find(Visit::class, $visit->getId());
        self::assertNull($reloaded->getClientNoteEn(), 'A failed translation leaves no English trace.');
        self::assertNotNull($reloaded->getClientNoteSentAt(), 'The send is stamped despite the translation failure.');
    }

    public function testAFrenchOnlyAudienceNeverCallsTheTranslation(): void
    {
        $this->client->disableReboot();
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        $visit = $this->persistVisit(withPersons: true, frenchOnly: true);
        $visit->setClientNote('Bel appartement, dossier à envoyer vite.');
        // Trace d'un envoi précédent : elle ne doit pas bouger quand aucune
        // traduction n'est demandée.
        $visit->setClientNoteEn('Stale translation from a previous send.');
        $this->em->flush();
        $token = $this->noteToken($visit);
        $agent = $this->translationAgent();
        $this->installTranslationStub($agent);

        $this->client->request('POST', $this->visitUrl($visit).'/note-client/envoyer', [
            '_token' => $token,
        ]);

        self::assertResponseStatusCodeSame(303);
        self::assertEmailCount(1);
        self::assertSame(0, $agent->calls, 'No model call when every recipient reads French.');

        $this->em->clear();
        self::assertSame(
            'Stale translation from a previous send.',
            $this->em->find(Visit::class, $visit->getId())->getClientNoteEn(),
            'client_note_en is left untouched without an English-speaking recipient.',
        );
    }

    public function testAResendAfterAFrenchEditRewritesTheTranslation(): void
    {
        $this->client->disableReboot();
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        $visit = $this->persistVisit(withPersons: true);
        $visit->setClientNote('Première version.');
        $this->em->flush();
        $token = $this->noteToken($visit);
        $this->installTranslationStub($this->translationAgent());

        $this->client->request('POST', $this->visitUrl($visit).'/note-client/envoyer', ['_token' => $token]);
        self::assertResponseStatusCodeSame(303);
        $this->em->clear();
        self::assertSame('Translated: Première version.', $this->em->find(Visit::class, $visit->getId())->getClientNoteEn());

        // Retouche de la note française puis renvoi : la traduction est
        // régénérée depuis le texte final et écrase la précédente.
        $this->client->request('POST', $this->visitUrl($visit).'/note-client', [
            '_token' => $token,
            'clientNote' => 'Version corrigée après envoi.',
        ]);
        self::assertResponseStatusCodeSame(303);
        $this->client->request('POST', $this->visitUrl($visit).'/note-client/envoyer', ['_token' => $token]);
        self::assertResponseStatusCodeSame(303);

        $this->em->clear();
        self::assertSame(
            'Translated: Version corrigée après envoi.',
            $this->em->find(Visit::class, $visit->getId())->getClientNoteEn(),
        );
    }

    public function testItRejectsAnInvalidCsrfToken(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        $visit = $this->persistVisit(withPersons: true);
        $visit->setClientNote('X');
        $this->em->flush();

        $this->client->request('POST', $this->visitUrl($visit).'/note-client/envoyer', ['_token' => 'not-valid']);

        self::assertResponseStatusCodeSame(403);
        self::assertEmailCount(0);
    }

    public function testItRefusesTheSendWithoutTheVisitsSection(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_CONTACTS']);
        $visit = $this->persistVisit(withPersons: true);
        $visit->setClientNote('X');
        $this->em->flush();

        $this->client->request('POST', $this->visitUrl($visit).'/note-client/envoyer', ['_token' => 'irrelevant']);

        self::assertResponseStatusCodeSame(403);
        self::assertEmailCount(0);
    }

    public function testTheSendButtonOnlyShowsWhenTheNoteIsFilled(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        $visit = $this->persistVisit(withPersons: true);

        $crawler = $this->client->request('GET', $this->visitUrl($visit));
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="visit-client-note-send"]'));

        $visit->setClientNote('Une note prête à partir.');
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->visitUrl($visit));
        self::assertCount(1, $crawler->filter('[data-testid="visit-client-note-send"]'));
        // Le garde-fou IA : la génération sur note remplie passe par la
        // modale de confirmation partagée.
        $generateForm = $crawler->filter('[data-testid="visit-client-note-generate"]')->closest('form');
        self::assertStringContainsString('confirm-dialog#intercept', (string) $generateForm->attr('data-action'));
    }

    public function testGenerateOnAnEmptyNoteSkipsTheConfirmation(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        $visit = $this->persistVisit(withPersons: true);

        $crawler = $this->client->request('GET', $this->visitUrl($visit));
        self::assertResponseIsSuccessful();
        $generateForm = $crawler->filter('[data-testid="visit-client-note-generate"]')->closest('form');
        self::assertNull($generateForm->attr('data-action'), 'An empty note generates directly, without the modal.');
    }

    /**
     * Agent IA stub pour la traduction : préfixe le texte reçu de
     * "Translated: " (ou jette, selon $failing) en comptant les appels.
     * Retourné tel quel pour que le test inspecte $calls.
     */
    private function translationAgent(bool $failing = false): AgentInterface
    {
        return new class($failing) implements AgentInterface {
            public int $calls = 0;

            public function __construct(private readonly bool $failing)
            {
            }

            public function call(string|MessageBag|UserMessage $input, array $options = []): ResultInterface
            {
                ++$this->calls;
                if ($this->failing) {
                    throw new \RuntimeException('Translation API unreachable.');
                }
                \assert($input instanceof MessageBag);
                $received = '';
                foreach ($input->getMessages() as $message) {
                    if ($message instanceof UserMessage) {
                        $received = implode('', array_map(static fn ($c) => $c->getText(), $message->getContent()));
                    }
                }

                return new TextResult('Translated: '.$received);
            }

            public function getName(): string
            {
                return 'translation-stub';
            }
        };
    }

    private function stubbedGenerator(AgentInterface $agent): VisitClientNoteGenerator
    {
        return new VisitClientNoteGenerator(
            $agent,
            static::getContainer()->get(VisitPropertyRecap::class),
            new NullLogger(),
            static::getContainer()->get('translator'),
        );
    }

    /** Remplace le générateur du conteneur (requiert disableReboot() avant). */
    private function installTranslationStub(AgentInterface $agent): void
    {
        static::getContainer()->set(VisitClientNoteGenerator::class, $this->stubbedGenerator($agent));
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

    /** Corps HTML du premier email dont le sujet commence par $prefix. */
    private function bodyOfMessageWithSubjectStarting(string $prefix): string
    {
        foreach (self::getMailerMessages() as $message) {
            if (str_starts_with((string) $message->getSubject(), $prefix)) {
                return (string) $message->getHtmlBody();
            }
        }

        self::fail(\sprintf('No email with a subject starting with "%s".', $prefix));
    }

    private function persistVisit(bool $withPersons, bool $frenchOnly = false): Visit
    {
        $dossier = (new Dossier())
            ->setName('Famille Martin')
            ->setReference('DS-'.random_int(100000, 999999))
            ->setPairingCode(substr(strtoupper(bin2hex(random_bytes(4))), 0, 6))
            ->setCreatedAt(new \DateTimeImmutable());
        if ($withPersons) {
            $dossier->addPerson((new DossierPerson())
                ->setRole(DossierPersonRole::TENANT)
                ->setFirstName('Jean')->setLastName('Martin')
                ->setEmail('jean@visit-note-send-test.local')
                ->setLanguage(ContactLanguage::FR)
                ->setPrimaryContact(true));
            if (!$frenchOnly) {
                $dossier->addPerson((new DossierPerson())
                    ->setRole(DossierPersonRole::TENANT)
                    ->setFirstName('Emma')->setLastName('Martin')
                    ->setEmail('emma@visit-note-send-test.local')
                    ->setLanguage(ContactLanguage::EN));
            }
            // Doublon d'adresse : un seul email part pour les deux fiches.
            $dossier->addPerson((new DossierPerson())
                ->setRole(DossierPersonRole::FOLLOW_UP)
                ->setFirstName('Jean bis')->setLastName('Martin')
                ->setEmail('JEAN@visit-note-send-test.local')
                ->setLanguage(ContactLanguage::FR));
            // Email invalide : ignoré sans bloquer les autres.
            $dossier->addPerson((new DossierPerson())
                ->setRole(DossierPersonRole::TENANT)
                ->setFirstName('Sans')->setLastName('Email')
                ->setEmail('not-an-email')
                ->setLanguage(ContactLanguage::FR));
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

    private function loginAs(array $roles): void
    {
        $user = (new User())
            ->setEmail(bin2hex(random_bytes(4)).'@visit-note-send-test.local')
            ->setFirstName('First')->setLastName('Last')
            ->setRoles($roles)->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);
    }
}
