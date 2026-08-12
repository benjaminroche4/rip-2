<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Auth\Entity\User;
use App\Auth\Service\RecoveryCodesPdfRenderer;
use App\Shared\Pdf\PdfOptions;
use App\Shared\Pdf\PdfRenderer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * PDF export of the 2FA recovery codes: only available while the one-time
 * display window is open (session copy), branded HTML carries every code.
 */
final class RecoveryCodesPdfTest extends WebTestCase
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
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@recovery-pdf-test.local')->execute();
    }

    public function testRenderedHtmlCarriesEveryCodeAndTheAccount(): void
    {
        $renderer = static::getContainer()->get(RecoveryCodesPdfRenderer::class);
        $codes = ['35526060', '82715365', '83706613'];

        $html = $renderer->renderHtml($codes, 'staff@relocation-in-paris.fr');

        foreach ($codes as $code) {
            self::assertStringContainsString($code, $html);
        }
        self::assertStringContainsString('staff@relocation-in-paris.fr', $html);
        self::assertStringContainsString('Codes de récupération', $html);
    }

    public function testDownloadIsRefusedOutsideTheDisplayWindow(): void
    {
        $this->loginAsStaff();

        $this->client->request('GET', '/fr/'.$this->adminPrefix.'/admin/profil/codes-recuperation.pdf');

        self::assertResponseStatusCodeSame(404);
    }

    public function testDownloadStreamsThePdfDuringTheWindow(): void
    {
        // Le backend PDF est doublé : pas d'appel réseau DocRaptor en test.
        // Sans disableReboot, chaque requête reconstruit le conteneur et
        // perdrait le doublon.
        $this->client->disableReboot();
        static::getContainer()->set(PdfRenderer::class, new class implements PdfRenderer {
            public function render(string $html, ?PdfOptions $options = null): string
            {
                return '%PDF-fake '.md5($html);
            }
        });

        $this->loginAsStaff();
        // Ouvre la fenêtre d'affichage unique comme le ferait l'activation :
        // une première requête matérialise la session, on y pose les codes.
        $this->client->request('GET', '/fr/'.$this->adminPrefix.'/admin/profil');
        $session = $this->client->getRequest()->getSession();
        $session->set('two_factor_recovery_codes', ['35526060', '82715365']);
        $session->save();

        $this->client->request('GET', '/fr/'.$this->adminPrefix.'/admin/profil/codes-recuperation.pdf');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertStringContainsString('codes-recuperation-relocation-in-paris.pdf', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringStartsWith('%PDF-fake', (string) $this->client->getResponse()->getContent());
    }

    private function loginAsStaff(): void
    {
        $user = (new User())
            ->setEmail(bin2hex(random_bytes(4)).'@recovery-pdf-test.local')
            ->setFirstName('First')->setLastName('Last')
            ->setRoles(['ROLE_STAFF'])->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);
    }
}
