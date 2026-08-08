<?php

declare(strict_types=1);

namespace App\Tests\Admin\Components;

use App\Auth\Entity\User;
use App\Contact\Domain\ClosureReason;
use App\Contact\Entity\Contact;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\UX\LiveComponent\LiveResponder;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * Covers the business-qualification live components added on the contact
 * detail page: planned recall, closure reason and the structured housing
 * project.
 */
final class ContactQualificationTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.Contact::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@qualif-test.local')->execute();
    }

    public function testRecallDateCanBeSavedAndCleared(): void
    {
        $contact = $this->persistContact();
        $this->loginAsAdmin();

        $futureRecall = new \DateTimeImmutable('+2 days 14:30');

        $component = $this->mountTwigComponent('Admin:ContactFollowUp', ['contactId' => (int) $contact->getId()]);
        $component->setLiveResponder(new LiveResponder());
        $component->recallAt = $futureRecall->format('Y-m-d\TH:i');
        $component->saveRecall();

        $this->em->clear();
        self::assertSame($futureRecall->format('Y-m-d H:i'), $this->em->find(Contact::class, $contact->getId())->getRecallAt()?->format('Y-m-d H:i'));

        $component->clearRecall();
        $this->em->clear();
        self::assertNull($this->em->find(Contact::class, $contact->getId())->getRecallAt());
    }

    public function testUnparseableRecallIsIgnored(): void
    {
        $contact = $this->persistContact();
        $this->loginAsAdmin();

        $component = $this->mountTwigComponent('Admin:ContactFollowUp', ['contactId' => (int) $contact->getId()]);
        $component->setLiveResponder(new LiveResponder());
        $component->recallAt = 'garbage';
        $component->saveRecall();

        $this->em->clear();
        self::assertNull($this->em->find(Contact::class, $contact->getId())->getRecallAt());
        self::assertSame('', $component->recallAt);
    }

    public function testPastRecallIsRejected(): void
    {
        $contact = $this->persistContact();
        $this->loginAsAdmin();

        $component = $this->mountTwigComponent('Admin:ContactFollowUp', ['contactId' => (int) $contact->getId()]);
        $component->setLiveResponder(new LiveResponder());
        $component->recallAt = (new \DateTimeImmutable('-1 day'))->format('Y-m-d\TH:i');
        $component->saveRecall();

        $this->em->clear();
        self::assertNull($this->em->find(Contact::class, $contact->getId())->getRecallAt());
        self::assertSame('', $component->recallAt);
    }

    public function testPastMoveInDateIsRejected(): void
    {
        $contact = $this->persistContact();
        $this->loginAsAdmin();

        $component = $this->mountTwigComponent('Admin:ContactProject', ['contactId' => (int) $contact->getId()]);
        $component->toggleLock(); // fields start locked (anti-missclick)
        $component->setLiveResponder(new LiveResponder());
        $component->moveInAt = (new \DateTimeImmutable('-1 day'))->format('Y-m-d');
        $component->save();

        $this->em->clear();
        self::assertNull($this->em->find(Contact::class, $contact->getId())->getProjectMoveInAt());
    }

    public function testPropertyTypeChipTogglesAndPersists(): void
    {
        $contact = $this->persistContact();
        $this->loginAsAdmin();

        $component = $this->mountTwigComponent('Admin:ContactProject', ['contactId' => (int) $contact->getId()]);
        $component->toggleLock(); // fields start locked (anti-missclick)
        $component->setLiveResponder(new LiveResponder());

        $component->togglePropertyType('t3');
        $this->em->clear();
        self::assertSame('t3', $this->em->find(Contact::class, $contact->getId())->getProjectPropertyType());

        // Multi-select: a second type adds up, it does not replace.
        $component->togglePropertyType('loft');
        $this->em->clear();
        self::assertSame('t3,loft', $this->em->find(Contact::class, $contact->getId())->getProjectPropertyType());
        $component->togglePropertyType('loft');

        // Clicking the selected chip again clears the type.
        $component->togglePropertyType('t3');
        $this->em->clear();
        self::assertNull($this->em->find(Contact::class, $contact->getId())->getProjectPropertyType());

        $this->expectException(BadRequestHttpException::class);
        $component->togglePropertyType('castle');
    }

    public function testStayDurationChipTogglesAndPersists(): void
    {
        $contact = $this->persistContact();
        $this->loginAsAdmin();

        $component = $this->mountTwigComponent('Admin:ContactProject', ['contactId' => (int) $contact->getId()]);
        $component->toggleLock(); // fields start locked (anti-missclick)
        $component->setLiveResponder(new LiveResponder());

        $component->chooseStayDuration('medium');
        $this->em->clear();
        self::assertSame(\App\Contact\Domain\StayDuration::Medium, $this->em->find(Contact::class, $contact->getId())->getProjectStayDuration());

        // Clicking the selected chip again clears it.
        $component->chooseStayDuration('medium');
        $this->em->clear();
        self::assertNull($this->em->find(Contact::class, $contact->getId())->getProjectStayDuration());

        $this->expectException(BadRequestHttpException::class);
        $component->chooseStayDuration('forever');
    }

    public function testFurnishingAndGuarantorChipsToggleAndPersist(): void
    {
        $contact = $this->persistContact();
        $this->loginAsAdmin();

        $component = $this->mountTwigComponent('Admin:ContactProject', ['contactId' => (int) $contact->getId()]);
        $component->toggleLock(); // fields start locked (anti-missclick)
        $component->setLiveResponder(new LiveResponder());

        $component->chooseFurnishing('furnished');
        $component->chooseGuarantorType('garantme');
        $this->em->clear();
        $reloaded = $this->em->find(Contact::class, $contact->getId());
        self::assertSame('furnished', $reloaded->getProjectFurnishing());
        self::assertSame(\App\Contact\Domain\GuarantorType::Garantme, $reloaded->getProjectGuarantorType());

        // Multi-select: open to both.
        $component->chooseFurnishing('unfurnished');
        $this->em->clear();
        self::assertSame('furnished,unfurnished', $this->em->find(Contact::class, $contact->getId())->getProjectFurnishing());

        // Clicking the selected chips again clears them.
        $component->chooseFurnishing('furnished');
        $component->chooseFurnishing('unfurnished');
        $component->chooseGuarantorType('garantme');
        $this->em->clear();
        $reloaded = $this->em->find(Contact::class, $contact->getId());
        self::assertNull($reloaded->getProjectFurnishing());
        self::assertNull($reloaded->getProjectGuarantorType());

        $this->expectException(BadRequestHttpException::class);
        $component->chooseGuarantorType('crypto');
    }

    public function testProjectNoteIsSavedAndSwitchesToReadMode(): void
    {
        $contact = $this->persistContact();
        $this->loginAsAdmin();

        $component = $this->mountTwigComponent('Admin:ContactProject', ['contactId' => (int) $contact->getId()]);
        $component->toggleLock(); // fields start locked (anti-missclick)
        $component->setLiveResponder(new LiveResponder());
        self::assertTrue($component->editingProjectNote, 'Empty note starts in edit mode.');

        $component->projectNote = '  Priorité au calme, télétravail.  ';
        $component->saveProjectNote();

        $this->em->clear();
        self::assertSame('Priorité au calme, télétravail.', $this->em->find(Contact::class, $contact->getId())->getProjectNote());
        self::assertFalse($component->editingProjectNote, 'Saved note switches to read mode.');

        $component->startEditingProjectNote();
        self::assertTrue($component->editingProjectNote);

        // A fresh mount prefills from storage.
        $fresh = $this->mountTwigComponent('Admin:ContactProject', ['contactId' => (int) $contact->getId()]);
        self::assertSame('Priorité au calme, télétravail.', $fresh->projectNote);
        self::assertFalse($fresh->editingProjectNote);
    }

    public function testContactDetailsCanBeCorrectedByAdmin(): void
    {
        $contact = $this->persistContact();
        $this->loginAsAdmin();

        $component = $this->mountTwigComponent('Admin:ContactDetails', ['contactId' => (int) $contact->getId()]);
        $component->setLiveResponder(new LiveResponder());
        self::assertFalse($component->editing);

        $component->startEditing();
        self::assertTrue($component->editing);

        $component->firstName = '  Marie ';
        $component->lastName = 'Curie';
        $component->email = 'marie@example.com';
        $component->phoneNumber = '+33611223344';
        $component->company = 'institut curie';
        $component->chooseOffer('accompagne');
        $component->chooseLang('en');
        $component->chooseSource('whatsapp');
        // Switching away from the housing search drops the package, like on
        // the public form where the choice only exists for that help type.
        $component->chooseHelpType('contact.contactForm.helpType.choice.2');
        $component->saveDetails();

        self::assertFalse($component->editing);
        $this->em->clear();
        $reloaded = $this->em->find(Contact::class, $contact->getId());
        self::assertSame('Marie', $reloaded->getFirstName());
        self::assertSame('Institut curie', $reloaded->getCompany(), 'The company always leads with a capital.');
        self::assertSame('marie@example.com', $reloaded->getEmail());
        self::assertSame('contact.contactForm.helpType.choice.2', $reloaded->getHelpType());
        self::assertNull($reloaded->getOffer(), 'No package outside the housing search.');
        self::assertSame('en', $reloaded->getLang());
        self::assertSame(\App\Contact\Domain\ContactSource::Whatsapp, $reloaded->getSource());

        // Back to the housing search: the package can be set again.
        $component->startEditing();
        $component->chooseHelpType('contact.contactForm.helpType.choice.1');
        $component->chooseOffer('accompagne');
        $component->saveDetails();
        $this->em->clear();
        self::assertSame('accompagne', $this->em->find(Contact::class, $contact->getId())->getOffer());
    }

    public function testInvalidDetailsAreRejectedWithFieldErrors(): void
    {
        $contact = $this->persistContact();
        $this->loginAsAdmin();

        $component = $this->mountTwigComponent('Admin:ContactDetails', ['contactId' => (int) $contact->getId()]);
        $component->setLiveResponder(new LiveResponder());
        $component->startEditing();

        $component->firstName = '';
        $component->email = 'not-an-email';
        $component->saveDetails();

        self::assertTrue($component->editing, 'Errors keep the edit mode open.');
        self::assertArrayHasKey('firstName', $component->errors);
        self::assertArrayHasKey('email', $component->errors);

        $this->em->clear();
        self::assertSame('jane', $this->em->find(Contact::class, $contact->getId())->getFirstName(), 'Nothing was saved.');

        $component->firstName = 'Jane';
        $component->email = 'jane@example.com';
        $component->helpType = 'hacked';
        $this->expectException(BadRequestHttpException::class);
        $component->saveDetails();
    }

    public function testFollowUpTimelineRendersTheChronologicalHistory(): void
    {
        $contact = $this->persistContact();
        $this->loginAsAdmin();

        $repo = self::getContainer()->get(\App\Contact\Repository\ContactRepository::class);
        $repo->updateStatus((int) $contact->getId(), \App\Contact\Domain\ContactStatus::InProgress, 'Julien Moreau', null);
        $repo->updateStatus((int) $contact->getId(), \App\Contact\Domain\ContactStatus::Converted, 'Julien Moreau', null);

        $html = (string) $this->renderTwigComponent('Admin:ContactFollowUp', ['contactId' => (int) $contact->getId()]);

        self::assertSame(2, substr_count($html, 'data-testid="timeline-event"'));
        self::assertStringContainsString('Statut passé à', $html);
        self::assertStringContainsString('En cours', $html);
        self::assertStringContainsString('Convertie', $html);
        self::assertStringContainsString('Julien Moreau', $html);
        // Chronological: "En cours" before "Convertie".
        self::assertLessThan(strpos($html, 'Convertie'), strpos($html, 'En cours'));
    }

    public function testTimelineCollapsesBeyondFiveEventsAndExpands(): void
    {
        $contact = $this->persistContact();
        $this->loginAsAdmin();

        $repo = self::getContainer()->get(\App\Contact\Repository\ContactRepository::class);
        $cycle = ['in_progress', 'converted', 'in_progress', 'closed', 'in_progress', 'converted', 'in_progress'];
        foreach ($cycle as $status) {
            $repo->updateStatus((int) $contact->getId(), \App\Contact\Domain\ContactStatus::from($status), 'Julien Moreau', null);
        }

        $component = $this->mountTwigComponent('Admin:ContactFollowUp', ['contactId' => (int) $contact->getId()]);
        $component->setLiveResponder(new LiveResponder());

        self::assertCount(5, $component->getTimelineEvents(), 'Collapsed to the 5 most recent.');
        self::assertSame(2, $component->getHiddenTimelineCount());

        $component->toggleTimeline();
        self::assertCount(7, $component->getTimelineEvents());
        self::assertSame(0, $component->getHiddenTimelineCount());

        $component->toggleTimeline();
        self::assertCount(5, $component->getTimelineEvents());
    }

    public function testContactCanBeAssignedAndUnassigned(): void
    {
        $contact = $this->persistContact();
        $this->loginAsAdmin();
        $admin = self::getContainer()->get('security.token_storage')->getToken()->getUser();
        self::assertInstanceOf(User::class, $admin);

        $component = $this->mountTwigComponent('Admin:ContactFollowUp', ['contactId' => (int) $contact->getId()]);
        $component->setLiveResponder(new LiveResponder());

        $component->assign((int) $admin->getId());
        $this->em->clear();
        self::assertSame((int) $admin->getId(), $this->em->find(Contact::class, $contact->getId())->getAssignedTo()?->getId());

        $component->assign(0);
        $this->em->clear();
        self::assertNull($this->em->find(Contact::class, $contact->getId())->getAssignedTo());
    }

    public function testUnknownAssigneeIsRejected(): void
    {
        $contact = $this->persistContact();
        $this->loginAsAdmin();

        $component = $this->mountTwigComponent('Admin:ContactFollowUp', ['contactId' => (int) $contact->getId()]);
        $component->setLiveResponder(new LiveResponder());

        $this->expectException(BadRequestHttpException::class);
        $component->assign(999999999);
    }

    public function testClosureReasonIsSaved(): void
    {
        $contact = $this->persistContact();
        $this->loginAsAdmin();

        $component = $this->mountTwigComponent('Admin:ContactStatusControl', ['contactId' => (int) $contact->getId()]);
        $component->setLiveResponder(new LiveResponder());
        $component->setClosureReason('unreachable');

        $this->em->clear();
        self::assertSame(ClosureReason::Unreachable, $this->em->find(Contact::class, $contact->getId())->getClosureReason());
    }

    public function testNextStepTogglesAndClearsWhenLeavingInProgress(): void
    {
        $contact = $this->persistContact();
        $this->loginAsAdmin();

        $component = $this->mountTwigComponent('Admin:ContactStatusControl', ['contactId' => (int) $contact->getId()]);
        $component->setLiveResponder(new LiveResponder());

        $component->change('in_progress');
        $component->setNextStep('visit');
        $this->em->clear();
        self::assertSame(\App\Contact\Domain\NextStep::Visit, $this->em->find(Contact::class, $contact->getId())->getNextStep());

        // Clicking the selected chip again clears it.
        $component->setNextStep('visit');
        $this->em->clear();
        self::assertNull($this->em->find(Contact::class, $contact->getId())->getNextStep());

        // Leaving "in progress" drops a stale next step.
        $component->setNextStep('call');
        $component->change('converted');
        $this->em->clear();
        self::assertNull($this->em->find(Contact::class, $contact->getId())->getNextStep());

        $this->expectException(BadRequestHttpException::class);
        $component->setNextStep('teleport');
    }

    public function testUnknownClosureReasonIsRejected(): void
    {
        $contact = $this->persistContact();
        $this->loginAsAdmin();

        $component = $this->mountTwigComponent('Admin:ContactStatusControl', ['contactId' => (int) $contact->getId()]);
        $component->setLiveResponder(new LiveResponder());

        $this->expectException(BadRequestHttpException::class);
        $component->setClosureReason('nope');
    }

    public function testTerminalBannerShowsOnlyForClosedOrConverted(): void
    {
        $contact = $this->persistContact();
        $this->loginAsAdmin();

        $html = (string) $this->renderTwigComponent('Admin:ContactTerminalBanner', ['contactId' => (int) $contact->getId()]);
        self::assertStringNotContainsString('data-testid="terminal-banner"', $html, 'No banner while untreated.');

        $contact->setStatus(\App\Contact\Domain\ContactStatus::Closed)
            ->setClosureReason(ClosureReason::ProfileMismatch)
            ->setStatusChangedAt(new \DateTimeImmutable('2026-07-31 10:00'));
        $this->em->flush();

        $html = (string) $this->renderTwigComponent('Admin:ContactTerminalBanner', ['contactId' => (int) $contact->getId()]);
        self::assertStringContainsString('data-testid="terminal-banner"', $html);
        self::assertStringContainsString('Demande fermée', $html);
        self::assertStringContainsString('Profil inadapté à nos services', $html);

        $contact->setStatus(\App\Contact\Domain\ContactStatus::Converted);
        $this->em->flush();

        $html = (string) $this->renderTwigComponent('Admin:ContactTerminalBanner', ['contactId' => (int) $contact->getId()]);
        self::assertStringContainsString('data-testid="terminal-banner"', $html);
        self::assertStringContainsString('Demande convertie en client', $html);
        self::assertStringNotContainsString('Profil inadapté', $html, 'Closure reason is closed-only.');
    }

    public function testProjectFieldsAreSavedAndPrefilled(): void
    {
        $contact = $this->persistContact();
        $this->loginAsAdmin();

        $component = $this->mountTwigComponent('Admin:ContactProject', ['contactId' => (int) $contact->getId()]);
        $component->toggleLock(); // fields start locked (anti-missclick)
        $component->setLiveResponder(new LiveResponder());
        $component->budget = ' 2200 ';
        $component->areas = '  11e, 18e  ';
        $component->moveInAt = '2026-09-01';
        $component->propertyType = ' T2 meublé ';
        $component->save();

        $this->em->clear();
        $reloaded = $this->em->find(Contact::class, $contact->getId());
        self::assertSame(2200, $reloaded->getProjectBudget());
        self::assertSame('11e, 18e', $reloaded->getProjectAreas());
        self::assertSame('2026-09-01', $reloaded->getProjectMoveInAt()?->format('Y-m-d'));
        self::assertSame('T2 meublé', $reloaded->getProjectPropertyType());

        // A fresh mount prefills from what was stored.
        $fresh = $this->mountTwigComponent('Admin:ContactProject', ['contactId' => (int) $contact->getId()]);
        self::assertSame('2200', $fresh->budget);
        self::assertSame('11e, 18e', $fresh->areas);
        self::assertSame('2026-09-01', $fresh->moveInAt);
    }

    private function persistContact(): Contact
    {
        $contact = (new Contact())
            ->setFirstName('jane')->setLastName('doe')
            ->setEmail('jane+'.bin2hex(random_bytes(4)).'@example.com')
            ->setPhoneNumber('+33600000000')
            ->setHelpType('contact.contactForm.helpType.choice.1')
            ->setMessage('Hello')->setLang('fr')->setIp('127.0.0.1')
            ->setCreatedAt(new \DateTimeImmutable('today 10:00'));
        $this->em->persist($contact);
        $this->em->flush();

        return $contact;
    }

    private function loginAsAdmin(): void
    {
        $admin = (new User())
            ->setEmail(bin2hex(random_bytes(4)).'@qualif-test.local')
            ->setFirstName('First')->setLastName('Last')
            ->setRoles(['ROLE_ADMIN'])->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($admin);
        $this->em->flush();

        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($admin, 'main', $admin->getRoles()));
    }
}
