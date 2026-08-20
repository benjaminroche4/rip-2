<?php

declare(strict_types=1);

namespace App\Tests\Admin\Components;

use App\Auth\Entity\User;
use App\Contact\Domain\ClosureReason;
use App\Contact\Domain\ContactStatus;
use App\Contact\Domain\NextStep;
use App\Contact\Entity\Contact;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\UX\LiveComponent\LiveResponder;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * Client decisions of August 2026 on the status card: "Mission réalisée
 * avec succès" opens the closure reasons, and the "Prochaine étape"
 * mention only exists while the lead is in progress.
 */
final class ContactClosureAndNextStepVisibilityTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.Contact::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@closure-test.local')->execute();
    }

    private function persistContact(ContactStatus $status, ?NextStep $nextStep = null): Contact
    {
        $contact = (new Contact())
            ->setFirstName('jane')->setLastName('doe')
            ->setEmail('jane+'.bin2hex(random_bytes(4)).'@example.com')
            ->setPhoneNumber('+33600000000')
            ->setHelpType('contact.contactForm.helpType.choice.1')
            ->setMessage('Hello')->setLang('fr')->setIp('127.0.0.1')
            ->setCreatedAt(new \DateTimeImmutable('-1 day'))
            ->setStatus($status)
            ->setNextStep($nextStep);
        $this->em->persist($contact);
        $this->em->flush();

        return $contact;
    }

    private function loginAdmin(): User
    {
        $admin = (new User())
            ->setEmail(bin2hex(random_bytes(4)).'@closure-test.local')
            ->setFirstName('First')->setLastName('Last')
            ->setRoles(['ROLE_ADMIN'])->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($admin);
        $this->em->flush();
        self::getContainer()->get('security.token_storage')
            ->setToken(new UsernamePasswordToken($admin, 'main', $admin->getRoles()));

        return $admin;
    }

    public function testMissionAccomplishedIsTheFirstClosureReason(): void
    {
        self::assertSame(ClosureReason::MissionAccomplished, ClosureReason::cases()[0], 'The main closure reason comes first.');
        self::assertSame('admin.contacts.closure.mission_accomplished', ClosureReason::MissionAccomplished->labelKey());
    }

    public function testClosedLeadRendersMissionAccomplishedFirstAndItPersists(): void
    {
        $contact = $this->persistContact(ContactStatus::Closed);
        $this->loginAdmin();

        $html = (string) $this->renderTwigComponent('Admin:ContactStatusControl', ['contactId' => (int) $contact->getId()]);

        $missionPos = strpos($html, 'Mission réalisée avec succès');
        $budgetPos = strpos($html, 'Budget irréaliste');
        self::assertNotFalse($missionPos, 'The new reason renders in the closure block.');
        self::assertNotFalse($budgetPos);
        self::assertLessThan($budgetPos, $missionPos, 'Mission accomplished is the first chip.');

        /** @var \App\Admin\Twig\Components\ContactStatusControl $component */
        $component = $this->mountTwigComponent('Admin:ContactStatusControl', ['contactId' => (int) $contact->getId()]);
        $component->setLiveResponder(new LiveResponder());
        $component->chooseClosureReason('mission_accomplished');

        $this->em->clear();
        self::assertSame(ClosureReason::MissionAccomplished, $this->em->find(Contact::class, $contact->getId())->getClosureReason());
    }

    public function testNextStepCalloutOnlyShowsWhileInProgress(): void
    {
        $this->loginAdmin();

        $inProgress = $this->persistContact(ContactStatus::InProgress, NextStep::QuotePreparation);
        $html = (string) $this->renderTwigComponent('Admin:ContactStatusControl', ['contactId' => (int) $inProgress->getId()]);
        self::assertStringContainsString('next-step-callout', $html, 'In progress: the next-step card shows.');

        $converted = $this->persistContact(ContactStatus::Converted, NextStep::QuotePreparation);
        $html = (string) $this->renderTwigComponent('Admin:ContactStatusControl', ['contactId' => (int) $converted->getId()]);
        self::assertStringNotContainsString('next-step-callout', $html, 'Converted: no next-step mention.');

        $closed = $this->persistContact(ContactStatus::Closed, NextStep::QuotePreparation);
        $html = (string) $this->renderTwigComponent('Admin:ContactStatusControl', ['contactId' => (int) $closed->getId()]);
        self::assertStringNotContainsString('next-step-callout', $html, 'Closed: no next-step mention.');

        $new = $this->persistContact(ContactStatus::New, NextStep::QuotePreparation);
        $html = (string) $this->renderTwigComponent('Admin:ContactStatusControl', ['contactId' => (int) $new->getId()]);
        self::assertStringNotContainsString('next-step-callout', $html, 'New: no next-step mention.');
    }
}
