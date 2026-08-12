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
        }
    }

    public function testPhotosAreOpenableInTheFullscreenGallery(): void
    {
        $this->uploadPhotos([$this->pngUpload('salon.png'), $this->pngUpload('cuisine.png')]);
        $crawler = $this->client->followRedirect();

        // The grid is wired to the shared gallery controller, fed a JSON list
        // of {url, alt} pointing at the authenticated streaming route.
        $card = $crawler->filter('[data-controller="gallery"]');
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

        $this->client->request('GET', '/fr/'.$this->adminPrefix.'/admin/visites/'.$other->getId().'/photos/'.$photo->getId());
        self::assertResponseStatusCodeSame(404);
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
        return '/fr/'.$this->adminPrefix.'/admin/visites/'.$this->visit->getId();
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
