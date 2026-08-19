<?php

declare(strict_types=1);

namespace App\Tests\Visit;

use App\Auth\Entity\User;
use App\Dossier\Entity\Dossier;
use App\Visit\Entity\Visit;
use App\Visit\Entity\VisitPhoto;
use App\Visit\Storage\VisitPhotoPath;
use App\Visit\Storage\VisitPhotoStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Visit property photos: multipart upload into the photo storage (bucket
 * layout visits/<ref>/photos/), gallery on the visit page, authenticated
 * streaming, deletion, and the invalid-file guard.
 */
final class VisitPhotoTest extends WebTestCase
{
    private const PASSWORD = 'password';
    /** 1x1 transparent PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private KernelBrowser $client;
    private string $adminPrefix;
    private EntityManagerInterface $em;
    private Visit $visit;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->adminPrefix = (string) $container->getParameter('admin_path_prefix');
        $this->em = $container->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.VisitPhoto::class)->execute();
        $this->em->createQuery('DELETE FROM '.Visit::class)->execute();
        $this->em->createQuery('DELETE FROM '.Dossier::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@visit-photo-test.local')->execute();

        $dossier = (new Dossier())
            ->setName('Famille Martin')
            ->setReference('DS-'.random_int(100000, 999999))
            ->setPairingCode(substr(strtoupper(bin2hex(random_bytes(4))), 0, 6))
            ->setCreatedAt(new \DateTimeImmutable());
        $this->visit = (new Visit())
            ->setDossier($dossier)
            ->setReference('VS-'.random_int(100000, 999999))
            ->setScheduledAt(new \DateTimeImmutable('+2 days 10:00'))
            ->setAddress('12 rue de la Roquette, 75011 Paris')
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($dossier);
        $this->em->persist($this->visit);
        $this->em->flush();

        $this->loginAsAdmin();
    }

    public function testUploadStoresPhotosUnderTheVisitPrefixAndListsThem(): void
    {
        $this->uploadPhotos([$this->pngUpload('salon.png'), $this->pngUpload('cuisine.png')]);

        self::assertResponseStatusCodeSame(303);
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="visit-photos-grid"]');
        self::assertSame(2, $this->client->getCrawler()->filter('[data-testid="visit-photo"]')->count());

        /** @var list<VisitPhoto> $photos */
        $photos = $this->em->getRepository(VisitPhoto::class)->findBy(['visit' => $this->visit->getId()]);
        self::assertCount(2, $photos);
        foreach ($photos as $photo) {
            // Bucket convention: visits/<ref>/photos/<uuid>.<ext>, the
            // client file name never used as a storage key.
            self::assertMatchesRegularExpression(
                '#^visits/'.$this->visit->getReference().'/photos/[0-9a-f-]{36}\.png$#',
                (string) $photo->getPath(),
            );
            self::assertTrue($this->storage()->exists((string) $photo->getPath()));
            // Bibliothèque = photos de l'annonce : phase 'before' par défaut.
            self::assertSame('before', $photo->getPhase());
        }
    }

    public function testReportBlockUploadStoresAfterPhotosInTheirOwnGroup(): void
    {
        // Le bloc compte-rendu (visite effectuée) envoie phase=after : la
        // photo rejoint le groupe "Après la visite" du compte-rendu, pas la
        // bibliothèque des photos de l'annonce.
        $this->visit->setStatus(\App\Visit\Domain\VisitStatus::Done);
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->visitUrl());
        // ->first() : la zone compte-rendu peut porter plusieurs variantes
        // de design (exploration uidotsh) qui dupliquent le formulaire.
        $token = (string) $crawler->filter('[data-testid="visit-report-photos-input"]')->first()->closest('form')->filter('input[name="_token"]')->attr('value');
        $this->client->request('POST', $this->visitUrl().'/photos', ['_token' => $token, 'phase' => 'after'], ['photos' => [$this->pngUpload('salon.png')]]);
        self::assertResponseStatusCodeSame(303);

        /** @var VisitPhoto $photo */
        $photo = $this->em->getRepository(VisitPhoto::class)->findOneBy([]);
        self::assertSame('after', $photo->getPhase());

        $crawler = $this->client->followRedirect();
        // La photo vit dans la grille du compte-rendu; la bibliothèque reste
        // sur sa mention "aucune photo" (zéro photo d'annonce).
        self::assertGreaterThan(0, \count($crawler->filter('[data-testid="visit-report-photos-grid"] [data-testid="visit-report-photo"]')));
        self::assertCount(0, $crawler->filter('[data-testid="visit-photos-grid"]'));
        self::assertCount(1, $crawler->filter('[data-testid="visit-photos-none"]'));
    }

    public function testAForgedPhaseIsRejected(): void
    {
        $crawler = $this->client->request('GET', $this->visitUrl());
        $token = (string) $crawler->filter('[data-testid="visit-photos-input"]')->closest('form')->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', $this->visitUrl().'/photos', ['_token' => $token, 'phase' => 'during'], ['photos' => [$this->pngUpload('salon.png')]]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame(0, (int) $this->em->getRepository(VisitPhoto::class)->count([]));
    }

    public function testPhotosAreOpenableInTheFullscreenGallery(): void
    {
        $this->uploadPhotos([$this->pngUpload('salon.png'), $this->pngUpload('cuisine.png')]);
        $crawler = $this->client->followRedirect();

        // The grid is wired to the shared gallery controller, fed a JSON list
        // of {url, alt} pointing at the authenticated streaming route.
        $card = $crawler->filter('[data-controller~="gallery"]');
        self::assertCount(1, $card);
        $photos = json_decode((string) $card->attr('data-gallery-photos-value'), true);
        self::assertCount(2, $photos);
        self::assertStringContainsString('/photos/', (string) $photos[0]['url']);

        // Every thumbnail opens the lightbox at its own index, and the shared
        // lightbox dialog is present.
        self::assertSame(2, $crawler->filter('button[data-action="gallery#open"]')->count());
        self::assertSame('0', $crawler->filter('button[data-action="gallery#open"]')->first()->attr('data-gallery-index-param'));
        self::assertCount(1, $crawler->filter('dialog[data-gallery-target="dialog"]'));
    }

    public function testManagePencilIsPresentAndTheFormsStayInTheDom(): void
    {
        $this->uploadPhotos([$this->pngUpload('salon.png')]);
        $crawler = $this->client->followRedirect();

        // Bibliothèque par défaut : le stylo « Gérer » est là, mode gestion
        // éteint (aria-pressed=false), la bascule est purement client-side.
        $card = $crawler->filter('[data-testid="visit-photos"]');
        self::assertStringContainsString('photo-manage', (string) $card->attr('data-controller'));
        $pencil = $crawler->filter('[data-testid="visit-photos-manage"]');
        self::assertCount(1, $pencil);
        self::assertSame('false', $pencil->attr('aria-pressed'));

        // Les formulaires d'upload et de suppression restent dans le DOM en
        // permanence (masqués en CSS hors mode gestion) : le crawler et le
        // fallback no-JS les trouvent toujours.
        self::assertCount(1, $crawler->filter('[data-testid="visit-photos-input"]'));
        self::assertCount(1, $crawler->filter('[data-testid="visit-photo-delete"]'));
    }

    public function testRejectedFileStoresNothingAndFlashesTheCount(): void
    {
        $bad = tempnam(sys_get_temp_dir(), 'photo').'.txt';
        file_put_contents($bad, 'not an image');

        $this->uploadPhotos([new UploadedFile($bad, 'notes.txt', 'text/plain', test: true)]);
        $this->client->followRedirect();

        self::assertSelectorExists('[data-testid="visit-photos-rejected"]');
        self::assertSame(0, (int) $this->em->getRepository(VisitPhoto::class)->count([]));
        @unlink($bad);
    }

    public function testPhotoStreamsInlineWithNoStore(): void
    {
        $this->uploadPhotos([$this->pngUpload('salon.png')]);
        $photo = $this->em->getRepository(VisitPhoto::class)->findOneBy([]);

        $this->client->request('GET', $this->visitUrl().'/photos/'.$photo->getId());

        self::assertResponseIsSuccessful();
        self::assertSame('image/png', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        // BrowserKit captures the streamed callback output internally.
        self::assertSame(base64_decode(self::PNG), $this->client->getInternalResponse()->getContent());
    }

    public function testDownloadStreamsAsAttachmentUnderTheOriginalName(): void
    {
        $this->uploadPhotos([$this->pngUpload('salon.png')]);
        $photo = $this->em->getRepository(VisitPhoto::class)->findOneBy([]);

        $this->client->request('GET', $this->visitUrl().'/photos/'.$photo->getId().'?download=1');

        self::assertResponseIsSuccessful();
        $disposition = (string) $this->client->getResponse()->headers->get('Content-Disposition');
        self::assertStringStartsWith('attachment', $disposition);
        self::assertStringContainsString('salon.png', $disposition);
        self::assertSame(base64_decode(self::PNG), $this->client->getInternalResponse()->getContent());
    }

    public function testDownloadFallsBackToPhotoIdWhenTheOriginalNameIsNotAscii(): void
    {
        $this->uploadPhotos([$this->pngUpload('salon.png')]);
        /** @var VisitPhoto $photo */
        $photo = $this->em->getRepository(VisitPhoto::class)->findOneBy([]);
        // Nom d'origine non-ASCII : le paramètre filename= de la disposition
        // retombe sur photo-{id}, le nom réel passe en filename*(utf-8).
        $photo->setOriginalName('séjour.png');
        $this->em->flush();

        $this->client->request('GET', $this->visitUrl().'/photos/'.$photo->getId().'?download=1');

        self::assertResponseIsSuccessful();
        $disposition = (string) $this->client->getResponse()->headers->get('Content-Disposition');
        self::assertStringStartsWith('attachment', $disposition);
        self::assertStringContainsString('filename=photo-'.$photo->getId(), str_replace('"', '', $disposition));
        self::assertStringContainsString("filename*=utf-8''s%C3%A9jour.png", $disposition);
    }

    public function testPhotoOfAnotherVisitIs404(): void
    {
        $this->uploadPhotos([$this->pngUpload('salon.png')]);
        $photo = $this->em->getRepository(VisitPhoto::class)->findOneBy([]);

        // Fresh manager: the kernel rebooted during the upload requests,
        // the setUp entities are detached.
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $other = (new Visit())
            ->setDossier($em->find(Dossier::class, $this->visit->getDossier()->getId()))
            ->setReference('VS-'.random_int(100000, 999999))
            ->setScheduledAt(new \DateTimeImmutable('+3 days 10:00'))
            ->setAddress('Ailleurs')
            ->setCreatedAt(new \DateTimeImmutable());
        $em->persist($other);
        $em->flush();

        $this->client->request('GET', '/fr/'.$this->adminPrefix.'/admin/visites/'.$other->getReference().'/photos/'.$photo->getId());
        self::assertResponseStatusCodeSame(404);
    }

    public function testAnonymousPhotoStreamingNeverServesTheBytes(): void
    {
        $this->uploadPhotos([$this->pngUpload('salon.png')]);
        /** @var VisitPhoto $photo */
        $photo = $this->em->getRepository(VisitPhoto::class)->findOneBy([]);

        // Session jetée : la même URL, anonyme, part au challenge login sans
        // servir un octet de l'image (même convention que les documents de
        // dossier; un mauvais préfixe tombe, lui, en 404 direct).
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', $this->visitUrl().'/photos/'.$photo->getId());

        self::assertResponseStatusCodeSame(302);
        self::assertStringContainsString('connexion', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testDeleteRemovesTheRowAndTheStoredObject(): void
    {
        $this->uploadPhotos([$this->pngUpload('salon.png')]);
        /** @var VisitPhoto $photo */
        $photo = $this->em->getRepository(VisitPhoto::class)->findOneBy([]);
        $path = (string) $photo->getPath();
        self::assertTrue($this->storage()->exists($path));

        $crawler = $this->client->request('GET', $this->visitUrl());
        $this->client->submit($crawler->filter('[data-testid="visit-photo-delete"]')->closest('form')->form());

        self::assertResponseStatusCodeSame(303);
        self::assertSame(0, (int) $this->em->getRepository(VisitPhoto::class)->count([]));
        self::assertFalse($this->storage()->exists($path));
    }

    public function testDeleteRejectsAnInvalidCsrfToken(): void
    {
        $this->uploadPhotos([$this->pngUpload('salon.png')]);
        /** @var VisitPhoto $photo */
        $photo = $this->em->getRepository(VisitPhoto::class)->findOneBy([]);
        $path = (string) $photo->getPath();

        $this->client->request(
            'POST',
            $this->visitUrl().'/photos/'.$photo->getId().'/suppression',
            ['_token' => 'not-a-valid-token'],
        );

        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, (int) $this->em->getRepository(VisitPhoto::class)->count([]));
        self::assertTrue($this->storage()->exists($path), 'The stored object must survive a forged deletion.');
    }

    public function testDeletingAPhotoOfAnotherVisitIs404(): void
    {
        $this->uploadPhotos([$this->pngUpload('salon.png')]);
        /** @var VisitPhoto $photo */
        $photo = $this->em->getRepository(VisitPhoto::class)->findOneBy([]);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $other = (new Visit())
            ->setDossier($em->find(Dossier::class, $this->visit->getDossier()->getId()))
            ->setReference('VS-'.random_int(100000, 999999))
            ->setScheduledAt(new \DateTimeImmutable('+3 days 10:00'))
            ->setAddress('Ailleurs')
            ->setCreatedAt(new \DateTimeImmutable());
        $em->persist($other);
        $em->flush();

        // A valid token for the right action, but a visit id the photo does
        // not belong to: the ownership guard answers 404.
        $crawler = $this->client->request('GET', $this->visitUrl());
        $token = (string) $crawler->filter('[data-testid="visit-photo-delete"]')->closest('form')->filter('input[name="_token"]')->attr('value');

        $this->client->request(
            'POST',
            '/fr/'.$this->adminPrefix.'/admin/visites/'.$other->getReference().'/photos/'.$photo->getId().'/suppression',
            ['_token' => $token],
        );

        self::assertResponseStatusCodeSame(404);
        self::assertSame(1, (int) $this->em->getRepository(VisitPhoto::class)->count([]));
    }

    public function testDeleteRequiresTheVisitsSection(): void
    {
        $this->uploadPhotos([$this->pngUpload('salon.png')]);
        /** @var VisitPhoto $photo */
        $photo = $this->em->getRepository(VisitPhoto::class)->findOneBy([]);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $staff = (new User())
            ->setEmail('staff@visit-photo-test.local')
            ->setFirstName('Staff')->setLastName('NoVisits')
            ->setRoles(['ROLE_STAFF', 'ROLE_SECTION_CONTACTS'])->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $em->persist($staff);
        $em->flush();
        $this->client->loginUser($staff);

        $this->client->request(
            'POST',
            $this->visitUrl().'/photos/'.$photo->getId().'/suppression',
            ['_token' => 'irrelevant'],
        );

        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, (int) $this->em->getRepository(VisitPhoto::class)->count([]));
    }

    public function testDeleteOnAWrongPrefixIs404EvenAuthenticated(): void
    {
        $this->uploadPhotos([$this->pngUpload('salon.png')]);
        /** @var VisitPhoto $photo */
        $photo = $this->em->getRepository(VisitPhoto::class)->findOneBy([]);

        $this->client->request(
            'POST',
            '/fr/00000000000000000000000000000000/admin/visites/'.$this->visit->getReference().'/photos/'.$photo->getId().'/suppression',
            ['_token' => 'irrelevant'],
        );

        self::assertResponseStatusCodeSame(404);
        self::assertSame(1, (int) $this->em->getRepository(VisitPhoto::class)->count([]));
    }

    public function testDeletingTheVisitRemovesTheStoredObjects(): void
    {
        $this->uploadPhotos([$this->pngUpload('salon.png'), $this->pngUpload('cuisine.png')]);
        $paths = array_map(
            static fn (VisitPhoto $photo): string => (string) $photo->getPath(),
            $this->em->getRepository(VisitPhoto::class)->findBy(['visit' => $this->visit->getId()]),
        );
        self::assertCount(2, $paths);

        // Suppression de la visite : les lignes tombent par cascade et les
        // objets du préfixe visits/<ref>/photos/ quittent le stockage.
        $crawler = $this->client->request('GET', $this->visitUrl());
        $this->client->submit($crawler->filter('[data-testid="visit-show-delete"]')->closest('form')->form());

        self::assertResponseStatusCodeSame(303);
        self::assertSame(0, (int) $this->em->getRepository(VisitPhoto::class)->count([]));
        foreach ($paths as $path) {
            self::assertFalse($this->storage()->exists($path), 'Stored photo must be deleted with the visit: '.$path);
        }
    }

    public function testDeletingAPhotoSucceedsDespiteAStorageOutage(): void
    {
        $this->client->disableReboot();
        $photo = $this->persistPhotoRow();
        $crawler = $this->client->request('GET', $this->visitUrl());
        // La panne survient après le rendu de la page (le stub remplace le
        // stockage avant le POST qui l'utilise).
        static::getContainer()->set(VisitPhotoStorage::class, $this->failingStorage());

        $this->client->submit($crawler->filter('[data-testid="visit-photo-delete"]')->closest('form')->form());

        // Best-effort : la suppression métier aboutit malgré la panne.
        self::assertResponseStatusCodeSame(303);
        self::assertNull($this->em->find(VisitPhoto::class, $photo->getId()));
    }

    public function testDeletingTheVisitSucceedsDespiteAStorageOutage(): void
    {
        $this->client->disableReboot();
        $this->persistPhotoRow();
        $crawler = $this->client->request('GET', $this->visitUrl());
        static::getContainer()->set(VisitPhotoStorage::class, $this->failingStorage());

        $this->client->submit($crawler->filter('[data-testid="visit-show-delete"]')->closest('form')->form());

        self::assertResponseStatusCodeSame(303);
        self::assertNull($this->em->find(Visit::class, $this->visit->getId()));
        self::assertSame(0, (int) $this->em->getRepository(VisitPhoto::class)->count([]));
    }

    public function testAStoreFailureDuringUploadCountsAsRejectedWithoutBlocking(): void
    {
        $this->client->disableReboot();
        $crawler = $this->client->request('GET', $this->visitUrl());
        $token = (string) $crawler->filter('[data-testid="visit-photos-input"]')->closest('form')->filter('input[name="_token"]')->attr('value');
        static::getContainer()->set(VisitPhotoStorage::class, $this->failingStorage());

        $this->client->request('POST', $this->visitUrl().'/photos', ['_token' => $token], ['photos' => [$this->pngUpload('salon.png')]]);
        self::assertResponseStatusCodeSame(303);
        $this->client->followRedirect();

        // L'échec de stockage est compté comme rejet, rien n'est persisté.
        self::assertSelectorExists('[data-testid="visit-photos-rejected"]');
        self::assertSame(0, (int) $this->em->getRepository(VisitPhoto::class)->count([]));
    }

    /** Ligne photo posée directement en base, sans passer par le stockage. */
    private function persistPhotoRow(): VisitPhoto
    {
        $photo = (new VisitPhoto())
            ->setOriginalName('salon.png')
            ->setMimeType('image/png')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setPhase('before')
            ->setPath('visits/'.$this->visit->getReference().'/photos/00000000-0000-0000-0000-000000000001.png');
        $this->visit->addPhoto($photo);
        $this->em->persist($photo);
        $this->em->flush();

        return $photo;
    }

    /** Stockage en panne : tout accès objet échoue. */
    private function failingStorage(): VisitPhotoStorage
    {
        return new class implements VisitPhotoStorage {
            public function store(string $visitRef, UploadedFile $file): string
            {
                throw new \RuntimeException('Storage down.');
            }

            public function exists(string $path): bool
            {
                return true;
            }

            public function readStream(string $path)
            {
                throw new \RuntimeException('Storage down.');
            }

            public function delete(string $path): void
            {
                throw new \RuntimeException('Storage down.');
            }
        };
    }

    public function testPathGuardRejectsForeignShapes(): void
    {
        $this->expectException(\RuntimeException::class);
        VisitPhotoPath::guard('users/x/avatar/00000000-0000-0000-0000-000000000000.webp');
    }

    private function uploadPhotos(array $files): void
    {
        $crawler = $this->client->request('GET', $this->visitUrl());
        $token = (string) $crawler->filter('[data-testid="visit-photos-input"]')->closest('form')->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', $this->visitUrl().'/photos', ['_token' => $token], ['photos' => $files]);
    }

    private function pngUpload(string $name): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'photo').'.png';
        file_put_contents($tmp, base64_decode(self::PNG));

        return new UploadedFile($tmp, $name, 'image/png', test: true);
    }

    private function storage(): VisitPhotoStorage
    {
        return static::getContainer()->get(VisitPhotoStorage::class);
    }

    private function visitUrl(): string
    {
        return '/fr/'.$this->adminPrefix.'/admin/visites/'.$this->visit->getReference();
    }

    private function loginAsAdmin(): void
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get('security.user_password_hasher');
        $admin = (new User())
            ->setEmail('admin@visit-photo-test.local')
            ->setFirstName('Admin')->setLastName('Staff')
            ->setRoles(['ROLE_ADMIN'])
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $admin->setPassword($hasher->hashPassword($admin, self::PASSWORD));
        $this->em->persist($admin);
        $this->em->flush();

        $this->client->loginUser($admin);
    }
}
