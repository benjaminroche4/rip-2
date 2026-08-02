<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Auth\Entity\User;
use App\Contact\Entity\Contact;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Regression: the details help-type dropdown goes through the REAL
 * live-component HTTP flow. Guards against a missing LiveArg import: the
 * attribute then resolves to a nonexistent class in the component
 * namespace, the argument is silently never mapped and every click 500s
 * ("Could not resolve argument \$type").
 */
final class ContactDetailsLiveFlowTest extends WebTestCase
{
    public function testHelpTypeDropdownChoiceUpdatesTheEditState(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine.orm.entity_manager');
        $em->createQuery('DELETE FROM '.Contact::class)->execute();
        $em->createQuery('DELETE FROM '.User::class.' u WHERE u.email = :e')->setParameter('e', 'repro-details@example.com')->execute();

        $admin = (new User())
            ->setEmail('repro-details@example.com')
            ->setFirstName('Repro')->setLastName('Admin')
            ->setRoles(['ROLE_ADMIN'])->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $em->persist($admin);

        $contact = (new Contact())
            ->setFirstName('jane')->setLastName('doe')
            ->setEmail('repro@example.com')
            ->setPhoneNumber('+33600000000')
            ->setHelpType('contact.contactForm.helpType.choice.1')
            ->setMessage('Hello')->setLang('fr')->setIp('127.0.0.1')
            ->setCreatedAt(new \DateTimeImmutable('now'));
        $em->persist($contact);
        $em->flush();
        $reference = $contact->getReference();
        $client->loginUser($admin);

        $adminPrefix = (string) $container->getParameter('admin_path_prefix');
        $crawler = $client->request('GET', sprintf('/fr/%s/admin/contacts/%s', $adminPrefix, $reference));
        self::assertResponseIsSuccessful();

        // Extract the ContactProject live component root.
        $node = $crawler->filter('[data-testid="contact-details"]')->first();
        $props = json_decode($node->attr('data-live-props-value'), true);
        $csrf = $node->attr('data-live-csrf-value');
        $url = $node->attr('data-live-url-value');

        $props = $this->click($client, $url, $csrf, $props, 'contact.contactForm.helpType.choice.2');

        // The dropdown choice only updates the edit-mode prop; persistence
        // happens via saveDetails.
        self::assertSame('contact.contactForm.helpType.choice.2', $props['helpType']);
    }

    /** @return array live props after re-render */
    private function click(KernelBrowser $client, string $url, ?string $csrf, array $props, string $type): array
    {
        $client->request(
            'POST',
            $url.'/chooseHelpType',
            [
                'data' => json_encode([
                    'props' => $props,
                    'args' => ['type' => $type],
                ]),
            ],
            [],
            array_filter([
                'HTTP_ACCEPT' => 'application/vnd.live-component+html',
                'HTTP_X_CSRF_TOKEN' => $csrf,
            ]),
        );
        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode(), substr((string) $response->getContent(), 0, 500));

        $crawler = new \Symfony\Component\DomCrawler\Crawler((string) $response->getContent());
        $root = $crawler->filter('[data-testid="contact-details"]')->first();

        return json_decode($root->attr('data-live-props-value'), true);
    }
}
