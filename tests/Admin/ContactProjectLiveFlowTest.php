<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Auth\Entity\User;
use App\Contact\Entity\Contact;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Regression: the property-type chips go through the REAL live-component
 * HTTP flow (mount on the show page, then chip clicks with the props the
 * browser would resend). Guards against the PropertyAccessor pitfall where
 * an action named set<WritableProp>() is invoked during hydration and
 * toggles the selection on every request (multi-select became single).
 */
final class ContactProjectLiveFlowTest extends WebTestCase
{
    public function testTwoChipClicksAccumulate(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine.orm.entity_manager');
        $em->createQuery('DELETE FROM '.Contact::class)->execute();
        $em->createQuery('DELETE FROM '.User::class.' u WHERE u.email = :e')->setParameter('e', 'repro-live@example.com')->execute();

        $admin = (new User())
            ->setEmail('repro-live@example.com')
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
        $node = $crawler->filter('[data-testid="contact-project"]')->first();
        $props = json_decode($node->attr('data-live-props-value'), true);
        $csrf = $node->attr('data-live-csrf-value');
        $url = $node->attr('data-live-url-value');

        // Unlock the fields first (anti-missclick padlock, locked by default).
        $props = $this->action($client, $url, $csrf, $props, 'toggleLock', []);
        // First click: t2
        $props = $this->click($client, $url, $csrf, $props, 't2');
        // Second click: t3
        $props = $this->click($client, $url, $csrf, $props, 't3');

        $em->clear();
        self::assertSame('t2,t3', $em->find(Contact::class, $contact->getId())->getProjectPropertyType());
    }

    /** @return array live props after re-render */
    private function click(KernelBrowser $client, string $url, ?string $csrf, array $props, string $type): array
    {
        return $this->action($client, $url, $csrf, $props, 'togglePropertyType', ['type' => $type]);
    }

    /** @return array live props after re-render */
    private function action(KernelBrowser $client, string $url, ?string $csrf, array $props, string $action, array $args): array
    {
        $client->request(
            'POST',
            $url.'/'.$action,
            [
                'data' => json_encode([
                    'props' => $props,
                    'args' => $args,
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
        $root = $crawler->filter('[data-testid="contact-project"]')->first();

        return json_decode($root->attr('data-live-props-value'), true);
    }
}
