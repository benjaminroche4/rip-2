<?php

declare(strict_types=1);

namespace App\Tests\Admin\Components;

use App\Auth\Entity\User;
use App\Contact\Entity\Contact;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\UX\LiveComponent\LiveResponder;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class ContactStatusControlRenderTest extends KernelTestCase
{
    use InteractsWithTwigComponents;
    use InteractsWithLiveComponents;

    public function testFreshMountRendersEveryStatusState(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->createQuery('DELETE FROM '.Contact::class)->execute();

        $contact = (new Contact())
            ->setFirstName('jane')->setLastName('doe')
            ->setEmail('jane+render@example.com')
            ->setPhoneNumber('+33600000000')
            ->setHelpType('contact.contactForm.helpType.choice.1')
            ->setMessage('Hello')->setLang('fr')->setIp('127.0.0.1')
            ->setCreatedAt(new \DateTimeImmutable('-1 day 10:00'));
        $em->persist($contact);
        $em->flush();

        $admin = (new User())
            ->setEmail(bin2hex(random_bytes(4)).'@render-debug.local')
            ->setFirstName('First')->setLastName('Last')
            ->setRoles(['ROLE_ADMIN'])->setPassword('x')->setCreatedAt(new \DateTimeImmutable())->setProfileComplete(true)->setVerified(true);
        $em->persist($admin);
        $em->flush();
        $token = new UsernamePasswordToken($admin, 'main', $admin->getRoles());
        self::getContainer()->get('security.token_storage')->setToken($token);

        $component = $this->mountTwigComponent('Admin:ContactStatusControl', ['contactId' => (int) $contact->getId()]);
        $component->setLiveResponder(new LiveResponder());
        $component->change('in_progress');
        $component->pickStep('recontact');
        $component->pickChannel('whatsapp');
        $component->recallAt = (new \DateTimeImmutable('+2 days 14:00'))->format('Y-m-d\TH:i');
        $component->confirmStep();

        // Rendu de l'état "card récap recontact + canal" (mount frais).
        $html = (string) $this->renderTwigComponent('Admin:ContactStatusControl', ['contactId' => (int) $contact->getId()]);
        self::assertStringContainsString('next-step-callout', $html);

        // Visio.
        $component->editStep();
        $component->pickStep('recontact');
        $component->pickStep('visio');
        $component->recallAt = (new \DateTimeImmutable('+3 days 09:00'))->format('Y-m-d\TH:i');
        $component->confirmStep();
        $html = (string) $this->renderTwigComponent('Admin:ContactStatusControl', ['contactId' => (int) $contact->getId()]);
        self::assertStringContainsString('next-step-callout', $html);

        // Statuts terminaux.
        foreach (['closed', 'new', 'converted', 'in_progress'] as $status) {
            $component->change($status);
            $html = (string) $this->renderTwigComponent('Admin:ContactStatusControl', ['contactId' => (int) $contact->getId()]);
            self::assertStringContainsString('contact-status-dropdown', $html, \sprintf('State "%s" renders.', $status));
        }
    }

    public function testLiveActionsKeepTheCardRenderedAcrossTransitions(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->createQuery('DELETE FROM '.Contact::class)->execute();

        $contact = (new Contact())
            ->setFirstName('jane')->setLastName('doe')
            ->setEmail('jane+render2@example.com')
            ->setPhoneNumber('+33600000000')
            ->setHelpType('contact.contactForm.helpType.choice.1')
            ->setMessage('Hello')->setLang('fr')->setIp('127.0.0.1')
            ->setCreatedAt(new \DateTimeImmutable('-1 day 10:00'));
        $em->persist($contact);
        $admin = (new User())
            ->setEmail(bin2hex(random_bytes(4)).'@render-debug.local')
            ->setFirstName('First')->setLastName('Last')
            ->setRoles(['ROLE_ADMIN'])->setPassword('x')->setCreatedAt(new \DateTimeImmutable())->setProfileComplete(true)->setVerified(true);
        $em->persist($admin);
        $em->flush();

        $live = $this->createLiveComponent('Admin:ContactStatusControl', ['contactId' => (int) $contact->getId()])
            ->actingAs($admin);
        $live->call('change', ['status' => 'in_progress']);
        $live->call('pickStep', ['step' => 'quote_preparation']);
        $live->call('confirmStep');
        fwrite(STDERR, 'LIVE after confirm: '.(str_contains((string) $live->render(), 'next-step-callout') ? 'CALLOUT OK' : 'CALLOUT ABSENT')."\n");

        $live->call('change', ['status' => 'in_progress']);
        fwrite(STDERR, 'LIVE re-change in_progress: '.(str_contains((string) $live->render(), 'next-step-callout') ? 'CALLOUT OK' : 'CALLOUT ABSENT')."\n");

        $live->call('change', ['status' => 'closed']);
        self::assertStringContainsString('contact-status-dropdown', (string) $live->render(), 'Closed state still renders the status selector.');

        // Retour en cours : l'éditeur doit se rouvrir (plus d'étape).
        $live->call('change', ['status' => 'in_progress']);
        self::assertStringContainsString('data-testid="next-step"', (string) $live->render(), 'Back in progress with no step: the editor reopens.');

        // Étape datée complète (recontact + canal + date).
        $live->call('pickStep', ['step' => 'recontact']);
        $live->call('pickChannel', ['channel' => 'whatsapp']);
        $live->set('recallAt', (new \DateTimeImmutable('+2 days 14:00'))->format('Y-m-d\TH:i'));
        $live->call('confirmStep');
        $html = (string) $live->render();
        self::assertStringContainsString('next-step-callout', $html, 'Recontact recap card shows.');
        self::assertStringContainsString('recontact-channel-badge', $html, 'The chosen channel shows on the recap card.');

        // Modifier -> bascule vers une étape sans date.
        $live->call('editStep');
        $live->call('pickStep', ['step' => 'recontact']);
        $live->call('pickStep', ['step' => 'other']);
        $live->call('confirmStep');
        self::assertStringContainsString('next-step-callout', (string) $live->render(), 'Switching to a dateless step keeps a recap card.');

        // Re-update du statut en cours avec étape présente (cas signalé).
        $live->call('change', ['status' => 'in_progress']);
        $html = (string) $live->render();
        self::assertStringContainsString('next-step-callout', $html, 'Updating the status keeps the recap card visible.');
        self::assertStringNotContainsString('data-testid="next-step"', $html, 'The editor stays closed when a step is set.');
    }
}
