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
use Doctrine\ORM\EntityManagerInterface;
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
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        $visit = $this->persistVisit(withPersons: true);
        $visit->setClientNote('Bel appartement lumineux, nous recommandons de vous positionner.');
        $this->em->flush();

        $this->client->request('POST', $this->visitUrl($visit).'/note-client/envoyer', [
            '_token' => $this->noteToken($visit),
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
        $body = (string) self::getMailerMessages()[0]->getHtmlBody();
        self::assertStringContainsString('Bel appartement lumineux', $body);
        self::assertStringContainsString('12 rue de la Roquette', $body);

        $this->em->clear();
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
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        $visit = $this->persistVisit(withPersons: true);
        $visit->setClientNote('Première version.');
        $this->em->flush();

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
        $service = new \App\Visit\Service\VisitClientMailer($mailer, $container->get('translator'), $container->get('logger'));

        self::assertSame(['sent' => 1, 'total' => 2], $service->sendClientNote($visit));
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

    private function persistVisit(bool $withPersons): Visit
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
            $dossier->addPerson((new DossierPerson())
                ->setRole(DossierPersonRole::TENANT)
                ->setFirstName('Emma')->setLastName('Martin')
                ->setEmail('emma@visit-note-send-test.local')
                ->setLanguage(ContactLanguage::EN));
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
