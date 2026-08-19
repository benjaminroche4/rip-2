<?php

declare(strict_types=1);

namespace App\Tests\Visit;

use App\Auth\Entity\User;
use App\Dossier\Domain\ContactLanguage;
use App\Dossier\Domain\DossierPersonRole;
use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierPerson;
use App\Visit\Domain\VisitStatus;
use App\Visit\Entity\Visit;
use App\Visit\Entity\VisitPhoto;
use App\Visit\Service\VisitClientMailer;
use App\Visit\Storage\VisitPhotoStorage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * Photos jointes à l'email de note client : la modale propose la case
 * "Joindre les photos de l'annonce" (cochée par défaut, masquée sans photo
 * d'annonce), le POST transmet le choix, seules les photos de l'annonce
 * (phase before) partent (jamais celles prises en visite), les caps
 * (10 pièces, 15 Mo) tronquent sans faire échouer, et une photo illisible
 * est sautée en best-effort.
 */
final class VisitClientNoteAttachmentsTest extends WebTestCase
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

        $this->em->createQuery('DELETE FROM '.VisitPhoto::class)->execute();
        $this->em->createQuery('DELETE FROM '.Visit::class)->execute();
        $this->em->createQuery('DELETE FROM '.DossierPerson::class)->execute();
        $this->em->createQuery('DELETE FROM '.Dossier::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@visit-attach-test.local')->execute();
    }

    public function testTheAttachCheckboxOnlyShowsWhenTheVisitHasPhotos(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        $visit = $this->persistVisit();
        $visit->setClientNote('Une note prête à partir.');
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->visitUrl($visit));
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="visit-client-note-attach-photos"]'), 'No photo, no checkbox.');

        // Une photo prise en visite ne compte pas : la case reste absente.
        $this->addPhotoRow($visit, 'after', 'photo-visite.jpg', 'visits/'.$visit->getReference().'/photos/a.jpg');
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->visitUrl($visit));
        self::assertCount(0, $crawler->filter('[data-testid="visit-client-note-attach-photos"]'), 'Visit photos never surface the checkbox.');

        // Les reboots de kernel entre requêtes détachent l'entité : on la
        // recharge avant d'ajouter la photo d'annonce.
        $visit = $this->em->find(Visit::class, $visit->getId());
        \assert($visit instanceof Visit);
        $this->addPhotoRow($visit, 'before', 'annonce.jpg', 'visits/'.$visit->getReference().'/photos/b.jpg');
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->visitUrl($visit));
        $checkbox = $crawler->filter('[data-testid="visit-client-note-attach-photos"] input[name="attachPhotos"]');
        self::assertCount(1, $checkbox);
        self::assertNotNull($checkbox->attr('checked'), 'The checkbox is ticked by default.');
    }

    public function testTheSendPostForwardsTheCheckboxAndAttachesTheStoredPhotos(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        $visit = $this->persistVisit(withPerson: true);
        $visit->setClientNote('Voici notre retour complet.');

        // Un vrai fichier dans le storage local de test.
        $storage = static::getContainer()->get(VisitPhotoStorage::class);
        $tmp = tempnam(sys_get_temp_dir(), 'visit-attach');
        file_put_contents((string) $tmp, "\xFF\xD8\xFF".str_repeat('a', 500));
        $path = $storage->store((string) $visit->getReference(), new UploadedFile((string) $tmp, 'salon.jpg', 'image/jpeg', test: true));
        $this->addPhotoRow($visit, 'before', 'salon.jpg', $path);
        $this->em->flush();

        $this->client->request('POST', $this->visitUrl($visit).'/note-client/envoyer', [
            '_token' => $this->noteToken($visit),
            'attachPhotos' => '1',
        ]);

        self::assertResponseStatusCodeSame(303);
        self::assertEmailCount(1);
        $email = self::getMailerMessages()[0];
        \assert($email instanceof Email);
        self::assertCount(1, $email->getAttachments(), 'The stored photo travels as an attachment.');
        self::assertStringContainsString('salon.jpg', (string) $email->getAttachments()[0]->asDebugString());

        $storage->delete($path);
    }

    public function testAnUncheckedBoxSendsTheNoteWithoutAnyAttachment(): void
    {
        $this->loginAs(['ROLE_STAFF', 'ROLE_SECTION_VISITS']);
        $visit = $this->persistVisit(withPerson: true);
        $visit->setClientNote('Voici notre retour complet.');
        $this->addPhotoRow($visit, 'before', 'salon.jpg', 'visits/'.$visit->getReference().'/photos/a.jpg');
        $this->em->flush();

        // Case décochée : le champ n'est pas posté du tout.
        $this->client->request('POST', $this->visitUrl($visit).'/note-client/envoyer', [
            '_token' => $this->noteToken($visit),
        ]);

        self::assertResponseStatusCodeSame(303);
        self::assertEmailCount(1);
        $email = self::getMailerMessages()[0];
        \assert($email instanceof Email);
        self::assertCount(0, $email->getAttachments());
    }

    public function testOnlyListingPhotosAreAttachedAndTheCountCapTruncates(): void
    {
        $visit = $this->inMemoryVisit();
        // 2 photos prises en visite (jamais envoyées) + 11 photos d'annonce :
        // le cap de 10 pièces jointes tronque la dernière.
        $this->attachInMemoryPhoto($visit, 'after', 'after-1.jpg');
        $this->attachInMemoryPhoto($visit, 'after', 'after-2.jpg');
        for ($i = 1; $i <= 11; ++$i) {
            $this->attachInMemoryPhoto($visit, 'before', \sprintf('before-%02d.jpg', $i));
        }

        $sentEmails = new \ArrayObject();
        $service = $this->mailerService($this->capturingMailer($sentEmails), $this->stubStorage());

        self::assertSame(['sent' => 1, 'total' => 1], $service->sendClientNote($visit, true));
        self::assertCount(1, $sentEmails);
        $names = array_map(static fn ($part): string => $part->getFilename(), $sentEmails[0]->getAttachments());
        self::assertCount(10, $names, 'The attachment count cap truncates the extra photos.');
        self::assertNotContains('after-1.jpg', $names, 'Photos taken during the visit never reach the client.');
        self::assertNotContains('after-2.jpg', $names, 'Photos taken during the visit never reach the client.');
        self::assertSame('before-01.jpg', $names[0], 'The listing cover comes first.');
        self::assertNotContains('before-11.jpg', $names);
    }

    public function testTheCumulatedWeightCapTruncatesWithoutFailing(): void
    {
        $visit = $this->inMemoryVisit();
        $this->attachInMemoryPhoto($visit, 'before', 'big-1.jpg');
        $this->attachInMemoryPhoto($visit, 'before', 'big-2.jpg');
        $this->attachInMemoryPhoto($visit, 'before', 'big-3.jpg');

        $sentEmails = new \ArrayObject();
        // Chaque photo pèse 6 Mo : la troisième dépasse le cap de 15 Mo.
        $service = $this->mailerService($this->capturingMailer($sentEmails), $this->stubStorage(bytes: 6 * 1024 * 1024));

        self::assertSame(['sent' => 1, 'total' => 1], $service->sendClientNote($visit, true));
        self::assertCount(2, $sentEmails[0]->getAttachments(), 'The 15MB cumulated cap truncates the last photo.');
    }

    public function testAnUnreadablePhotoIsSkippedAndTheEmailStillGoesOut(): void
    {
        $visit = $this->inMemoryVisit();
        $this->attachInMemoryPhoto($visit, 'before', 'ok-1.jpg');
        $this->attachInMemoryPhoto($visit, 'before', 'broken.jpg');
        $this->attachInMemoryPhoto($visit, 'before', 'ok-2.jpg');

        $sentEmails = new \ArrayObject();
        $service = $this->mailerService($this->capturingMailer($sentEmails), $this->stubStorage(brokenName: 'broken.jpg'));

        self::assertSame(['sent' => 1, 'total' => 1], $service->sendClientNote($visit, true));
        $names = array_map(static fn ($part): string => $part->getFilename(), $sentEmails[0]->getAttachments());
        self::assertSame(['ok-1.jpg', 'ok-2.jpg'], $names, 'The unreadable photo is skipped, the send still happens.');
    }

    /**
     * @param \ArrayObject<int, Email> $sentEmails
     */
    private function capturingMailer(\ArrayObject $sentEmails): MailerInterface
    {
        return new class($sentEmails) implements MailerInterface {
            /** @param \ArrayObject<int, Email> $sentEmails */
            public function __construct(private readonly \ArrayObject $sentEmails)
            {
            }

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                \assert($message instanceof Email);
                $this->sentEmails->append($message);
            }
        };
    }

    private function mailerService(MailerInterface $mailer, VisitPhotoStorage $storage): VisitClientMailer
    {
        return new VisitClientMailer(
            $mailer,
            static::getContainer()->get('translator'),
            new NullLogger(),
            $storage,
            // Destinataires tous francophones ici : le générateur (donc le
            // modèle) n'est jamais appelé.
            static::getContainer()->get(\App\Visit\Service\VisitClientNoteGenerator::class),
        );
    }

    private function stubStorage(int $bytes = 100, ?string $brokenName = null): VisitPhotoStorage
    {
        return new class($bytes, $brokenName) implements VisitPhotoStorage {
            public function __construct(private readonly int $bytes, private readonly ?string $brokenName)
            {
            }

            public function store(string $visitRef, UploadedFile $file): string
            {
                throw new \LogicException('Not used in this stub.');
            }

            public function exists(string $path): bool
            {
                return true;
            }

            public function readStream(string $path)
            {
                if (null !== $this->brokenName && str_contains($path, $this->brokenName)) {
                    throw new \RuntimeException('Object unreadable.');
                }
                $stream = fopen('php://memory', 'r+');
                \assert(false !== $stream);
                fwrite($stream, str_repeat('a', $this->bytes));
                rewind($stream);

                return $stream;
            }

            public function delete(string $path): void
            {
            }
        };
    }

    private function inMemoryVisit(): Visit
    {
        $dossier = (new Dossier())
            ->setName('Famille Martin')
            ->setReference('DS-'.random_int(100000, 999999))
            ->setPairingCode(substr(strtoupper(bin2hex(random_bytes(4))), 0, 6))
            ->setCreatedAt(new \DateTimeImmutable());
        $dossier->addPerson((new DossierPerson())
            ->setRole(DossierPersonRole::TENANT)
            ->setFirstName('Jean')->setLastName('Martin')
            ->setEmail('jean@visit-attach-test.local')
            ->setLanguage(ContactLanguage::FR)
            ->setPrimaryContact(true));

        return (new Visit())
            ->setReference('VS-'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT))
            ->setDossier($dossier)
            ->setScheduledAt(new \DateTimeImmutable('-1 day 10:30'))
            ->setStatus(VisitStatus::Done)
            ->setAddress('12 rue de la Roquette, 75011 Paris')
            ->setClientNote('Voici notre retour complet.')
            ->setCreatedAt(new \DateTimeImmutable());
    }

    private function attachInMemoryPhoto(Visit $visit, string $phase, string $name): void
    {
        $visit->addPhoto((new VisitPhoto())
            ->setOriginalName($name)
            ->setMimeType('image/jpeg')
            ->setPhase($phase)
            ->setPath('visits/'.$visit->getReference().'/photos/'.$name)
            ->setCreatedAt(new \DateTimeImmutable()));
    }

    private function addPhotoRow(Visit $visit, string $phase, string $name, string $path): void
    {
        $visit->addPhoto((new VisitPhoto())
            ->setOriginalName($name)
            ->setMimeType('image/jpeg')
            ->setPhase($phase)
            ->setPath($path)
            ->setCreatedAt(new \DateTimeImmutable()));
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
                ->setEmail('jean@visit-attach-test.local')
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
            ->setEmail(bin2hex(random_bytes(4)).'@visit-attach-test.local')
            ->setFirstName('First')->setLastName('Last')
            ->setRoles($roles)->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);
    }
}
