<?php

declare(strict_types=1);

namespace App\Tests\Admin\Components;

use App\Auth\Entity\User;
use App\Contact\Domain\ContactStatus;
use App\Contact\Entity\Contact;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\UX\LiveComponent\LiveResponder;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * A closed lead freezes the housing project: the padlock cannot open and
 * every mutating action is refused server side. Reopening the lead makes
 * the section editable again.
 */
final class ContactProjectClosedLockTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.Contact::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@project-lock-test.local')->execute();
    }

    private function persistContact(ContactStatus $status): Contact
    {
        $contact = (new Contact())
            ->setFirstName('jane')->setLastName('doe')
            ->setEmail('jane+'.bin2hex(random_bytes(4)).'@example.com')
            ->setPhoneNumber('+33600000000')
            ->setHelpType('contact.contactForm.helpType.choice.1')
            ->setMessage('Hello')->setLang('fr')->setIp('127.0.0.1')
            ->setCreatedAt(new \DateTimeImmutable('-1 day'))
            ->setStatus($status)
            ->setProjectBudget(1500);
        $this->em->persist($contact);
        $this->em->flush();

        return $contact;
    }

    private function loginAdmin(): void
    {
        $admin = (new User())
            ->setEmail(bin2hex(random_bytes(4)).'@project-lock-test.local')
            ->setFirstName('First')->setLastName('Last')
            ->setRoles(['ROLE_ADMIN'])->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($admin);
        $this->em->flush();
        self::getContainer()->get('security.token_storage')
            ->setToken(new UsernamePasswordToken($admin, 'main', $admin->getRoles()));
    }

    public function testClosedLeadCannotBeUnlockedNorMutated(): void
    {
        $contact = $this->persistContact(ContactStatus::Closed);
        $this->loginAdmin();

        /** @var \App\Admin\Twig\Components\ContactProject $component */
        $component = $this->mountTwigComponent('Admin:ContactProject', ['contactId' => (int) $contact->getId()]);
        $component->setLiveResponder(new LiveResponder());
        self::assertTrue($component->isClosedLead());

        // The padlock refuses to open.
        $component->toggleLock();
        self::assertTrue($component->locked, 'A closed lead never unlocks.');

        // Even with a forged unlocked state, mutations are refused.
        $component->locked = false;
        $component->budget = '9999';
        $component->save();
        $component->togglePropertyType('t2');
        $component->chooseStayDuration('short');
        $component->chooseFurnishing('furnished');
        $component->chooseGuarantorType('physical');
        $component->projectNote = 'Interdit';
        $component->saveProjectNote();

        $this->em->clear();
        $reloaded = $this->em->find(Contact::class, $contact->getId());
        self::assertSame(1500, $reloaded->getProjectBudget(), 'Budget untouched on a closed lead.');
        self::assertNull($reloaded->getProjectPropertyType());
        self::assertNull($reloaded->getProjectStayDuration());
        self::assertNull($reloaded->getProjectFurnishing());
        self::assertNull($reloaded->getProjectGuarantorType());
        self::assertNull($reloaded->getProjectNote());
    }

    public function testClosedLeadRendersReadOnlyBanner(): void
    {
        $contact = $this->persistContact(ContactStatus::Closed);
        $this->loginAdmin();

        $html = (string) $this->renderTwigComponent('Admin:ContactProject', ['contactId' => (int) $contact->getId()]);

        self::assertStringContainsString('contact-project-closed-banner', $html);
        self::assertStringContainsString('disabled', $html, 'Fields are disabled via the fieldset.');
        self::assertStringNotContainsString('contact-project-lock', $html, 'No padlock on a closed lead.');
    }

    public function testReopenedLeadIsEditableAgain(): void
    {
        $contact = $this->persistContact(ContactStatus::InProgress);
        $this->loginAdmin();

        /** @var \App\Admin\Twig\Components\ContactProject $component */
        $component = $this->mountTwigComponent('Admin:ContactProject', ['contactId' => (int) $contact->getId()]);
        $component->setLiveResponder(new LiveResponder());
        self::assertFalse($component->isClosedLead());

        $component->toggleLock();
        self::assertFalse($component->locked, 'A reopened lead unlocks normally.');

        $component->budget = '2200';
        $component->save();

        $this->em->clear();
        self::assertSame(2200, $this->em->find(Contact::class, $contact->getId())->getProjectBudget());
    }
}
