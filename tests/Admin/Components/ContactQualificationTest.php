<?php

declare(strict_types=1);

namespace App\Tests\Admin\Components;

use App\Auth\Entity\User;
use App\Contact\Domain\ClosureReason;
use App\Contact\Entity\Contact;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Mime\Email;
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
    use MailerAssertionsTrait;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.Contact::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@qualif-test.local')->execute();
    }

    public function testRecallDateIsSavedOnConfirmAndClearedWithTheStep(): void
    {
        $contact = $this->persistContact();
        $this->loginAsAdmin();

        $futureRecall = new \DateTimeImmutable('+2 days 14:30');

        $component = $this->mountTwigComponent('Admin:ContactStatusControl', ['contactId' => (int) $contact->getId()]);
        $component->setLiveResponder(new LiveResponder());
        $component->change('in_progress');
        $component->pickStep('recontact');
        $component->pickChannel('whatsapp');
        $component->recallAt = $futureRecall->format('Y-m-d\TH:i');

        // Nothing persists while the draft is not confirmed.
        $this->em->clear();
        self::assertNull($this->em->find(Contact::class, $contact->getId())->getRecallAt());

        $component->confirmStep();
        $this->em->clear();
        $reloaded = $this->em->find(Contact::class, $contact->getId());
        self::assertSame($futureRecall->format('Y-m-d H:i'), $reloaded->getRecallAt()?->format('Y-m-d H:i'));
        self::assertSame(\App\Contact\Domain\RecontactChannel::Whatsapp, $reloaded->getRecontactChannel());

        // Switching to a dateless step purges the stale recall: one state only.
        $component->editStep();
        $component->pickStep('recontact');
        $component->pickStep('quote_preparation');
        $component->confirmStep();
        $this->em->clear();
        $reloaded = $this->em->find(Contact::class, $contact->getId());
        self::assertSame(\App\Contact\Domain\NextStep::QuotePreparation, $reloaded->getNextStep());
        self::assertNull($reloaded->getRecallAt());

        // Deselecting the step and confirming clears both step and date.
        $component->editStep();
        $component->pickStep('quote_preparation');
        $component->confirmStep();
        $this->em->clear();
        $reloaded = $this->em->find(Contact::class, $contact->getId());
        self::assertNull($reloaded->getNextStep());
        self::assertNull($reloaded->getRecallAt());
    }

    public function testMissingChannelBlocksARecontactConfirmation(): void
    {
        $contact = $this->persistContact();
        $this->loginAsAdmin();

        $component = $this->mountTwigComponent('Admin:ContactStatusControl', ['contactId' => (int) $contact->getId()]);
        $component->setLiveResponder(new LiveResponder());
        $component->change('in_progress');
        $component->pickStep('recontact');
        $component->recallAt = (new \DateTimeImmutable('+2 days'))->format('Y-m-d\TH:i');
        $component->confirmStep();

        // Blocked: the draft persists nothing and the editor flags it.
        self::assertTrue($component->channelMissing);
        $this->em->clear();
        self::assertNull($this->em->find(Contact::class, $contact->getId())->getNextStep());

        $component->pickChannel('email');
        $component->confirmStep();
        self::assertFalse($component->channelMissing);
        $this->em->clear();
        self::assertSame(\App\Contact\Domain\RecontactChannel::Email, $this->em->find(Contact::class, $contact->getId())->getRecontactChannel());

        $this->expectException(BadRequestHttpException::class);
        $component->pickChannel('pigeon');
    }

    public function testUnparseableRecallBlocksTheConfirmation(): void
    {
        $contact = $this->persistContact();
        $this->loginAsAdmin();

        $component = $this->mountTwigComponent('Admin:ContactStatusControl', ['contactId' => (int) $contact->getId()]);
        $component->setLiveResponder(new LiveResponder());
        $component->change('in_progress');
        $component->pickStep('recontact');
        $component->recallAt = 'garbage';
        $component->confirmStep();

        // The half-filled draft persists nothing: no step, no date.
        $this->em->clear();
        $reloaded = $this->em->find(Contact::class, $contact->getId());
        self::assertNull($reloaded->getRecallAt());
        self::assertNull($reloaded->getNextStep());
        self::assertSame('', $component->recallAt);
        self::assertTrue($component->dateMissing);
    }

    public function testPastRecallBlocksTheConfirmation(): void
    {
        $contact = $this->persistContact();
        $this->loginAsAdmin();

        $component = $this->mountTwigComponent('Admin:ContactStatusControl', ['contactId' => (int) $contact->getId()]);
        $component->setLiveResponder(new LiveResponder());
        $component->change('in_progress');
        $component->pickStep('recontact');
        $component->recallAt = (new \DateTimeImmutable('-1 day'))->format('Y-m-d\TH:i');
        $component->confirmStep();

        $this->em->clear();
        $reloaded = $this->em->find(Contact::class, $contact->getId());
        self::assertNull($reloaded->getRecallAt());
        self::assertNull($reloaded->getNextStep());
        // The typed value stays on screen so the admin can fix the digit.
        self::assertNotSame('', $component->recallAt);
        self::assertTrue($component->dateMissing);
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

    public function testLockedBannerShowsOnlyWhileLocked(): void
    {
        $contact = $this->persistContact();
        $this->loginAsAdmin();

        $locked = (string) $this->renderTwigComponent('Admin:ContactProject', ['contactId' => (int) $contact->getId()]);
        self::assertStringContainsString('data-testid="contact-project-locked-banner"', $locked, 'Locked by default: the unlock banner shows.');

        $unlocked = (string) $this->renderTwigComponent('Admin:ContactProject', [
            'contactId' => (int) $contact->getId(),
            'locked' => false,
        ]);
        self::assertStringNotContainsString('data-testid="contact-project-locked-banner"', $unlocked);
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

        // La chronologie vit dans le drawer Suivi et notes (ContactNotes).
        /** @var \App\Admin\Twig\Components\ContactNotes $component */
        $component = $this->mountTwigComponent('Admin:ContactNotes', ['contactId' => (int) $contact->getId()]);
        $history = $component->getHistory();

        // Reçue le + 2 changements de statut, en ordre chronologique.
        self::assertCount(3, $history);
        self::assertStringContainsString('Reçu le', $history[0]['text']);
        self::assertStringContainsString('En cours', $history[1]['text']);
        self::assertStringContainsString('Converti', $history[2]['text']);
        self::assertSame('Julien Moreau', $history[1]['authorName']);

        // L'IP de la soumission n'est pas un auteur : elle part en infobulle,
        // sinon la ligne "Reçu le" affiche un avatar "I".
        self::assertNull($history[0]['authorName']);
        self::assertStringContainsString('IP :', (string) $history[0]['hint']);
    }

    public function testContactCanBeAssignedAndUnassigned(): void
    {
        $contact = $this->persistContact();
        $this->loginAsAdmin();
        $admin = self::getContainer()->get('security.token_storage')->getToken()->getUser();
        self::assertInstanceOf(User::class, $admin);

        $component = $this->mountTwigComponent('Admin:ContactStatusControl', ['contactId' => (int) $contact->getId()]);
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

        $component = $this->mountTwigComponent('Admin:ContactStatusControl', ['contactId' => (int) $contact->getId()]);
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
        $component->chooseClosureReason('unreachable');

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
        $component->pickStep('quote_sent');
        $component->recallAt = (new \DateTimeImmutable('+2 days'))->format('Y-m-d\TH:i');
        $component->confirmStep();
        $this->em->clear();
        self::assertSame(\App\Contact\Domain\NextStep::QuoteSent, $this->em->find(Contact::class, $contact->getId())->getNextStep());

        // Deselecting the chip and confirming clears the step.
        $component->editStep();
        $component->pickStep('quote_sent');
        $component->confirmStep();
        $this->em->clear();
        self::assertNull($this->em->find(Contact::class, $contact->getId())->getNextStep());

        // Leaving "in progress" drops a stale next step and its planned recall.
        $component->pickStep('recontact');
        $component->recallAt = (new \DateTimeImmutable('+2 days'))->format('Y-m-d\TH:i');
        $component->confirmStep();
        $component->change('converted');
        $this->em->clear();
        $reloaded = $this->em->find(Contact::class, $contact->getId());
        self::assertNull($reloaded->getNextStep());
        self::assertNull($reloaded->getRecallAt());

        $this->expectException(BadRequestHttpException::class);
        $component->pickStep('teleport');
    }

    public function testNextStepEditorClosesOnceTheStepIsFilledIn(): void
    {
        $contact = $this->persistContact();
        $this->loginAsAdmin();

        $component = $this->mountTwigComponent('Admin:ContactStatusControl', ['contactId' => (int) $contact->getId()]);
        $component->setLiveResponder(new LiveResponder());
        $component->change('in_progress');

        // A dateless step confirms right away: the recap card replaces the editor.
        $component->pickStep('quote_preparation');
        $component->confirmStep();
        self::assertFalse($component->editingStep);

        // "Modifier" reopens the editor prefilled with the stored step.
        $component->editStep();
        self::assertTrue($component->editingStep);
        self::assertSame('quote_preparation', $component->pendingStep);

        // Cancelling drops the draft and closes the editor.
        $component->pickStep('quote_sent');
        $component->cancelStep();
        self::assertFalse($component->editingStep);
        $this->em->clear();
        self::assertSame(\App\Contact\Domain\NextStep::QuotePreparation, $this->em->find(Contact::class, $contact->getId())->getNextStep());

        // A dated step without a date blocks the confirmation, the editor stays open.
        $component->editStep();
        $component->pickStep('quote_sent');
        $component->confirmStep();
        self::assertTrue($component->editingStep);
        self::assertTrue($component->dateMissing);

        $component->recallAt = (new \DateTimeImmutable('+2 days'))->format('Y-m-d\TH:i');
        $component->confirmStep();
        self::assertFalse($component->editingStep);
    }

    public function testPlanningAVisioSendsTheInviteEmails(): void
    {
        $contact = $this->persistContact();
        $this->loginAsAdmin();

        $component = $this->mountTwigComponent('Admin:ContactStatusControl', ['contactId' => (int) $contact->getId()]);
        $component->setLiveResponder(new LiveResponder());
        $component->change('in_progress');
        $component->pickStep('visio');
        $component->recallAt = (new \DateTimeImmutable('+2 days'))->format('Y-m-d\TH:i');

        // Still a draft: no invite goes out before the confirmation.
        self::assertEmailCount(0);

        $component->confirmStep();

        // One email to the prospect, one calendar invite to the agency
        // inbox. Messenger sync logs each email twice (queued + sent), so
        // match by recipient over the full list instead of by index.
        self::assertEmailCount(2);
        $byRecipient = [];
        foreach (self::getMailerMessages() as $message) {
            self::assertInstanceOf(\Symfony\Component\Mime\Email::class, $message);
            $byRecipient[$message->getTo()[0]->getAddress()] = $message;
        }
        self::assertArrayHasKey((string) $contact->getEmail(), $byRecipient);
        self::assertArrayHasKey('contact@relocation-in-paris.fr', $byRecipient);
        // The client email carries "add to calendar" links on top of the
        // attached invite (for prospects who never open attachments).
        self::assertEmailHtmlBodyContains($byRecipient[(string) $contact->getEmail()], 'calendar.google.com');
        self::assertEmailHtmlBodyContains($byRecipient[(string) $contact->getEmail()], 'outlook.live.com');
        // Subject and hero block: the essential info is readable at a glance.
        self::assertStringContainsString('Votre appel vidéo est confirmé', (string) $byRecipient[(string) $contact->getEmail()]->getSubject());
        self::assertEmailHtmlBodyContains($byRecipient[(string) $contact->getEmail()], 'heure de Paris');
        $agent = $byRecipient['contact@relocation-in-paris.fr'];
        self::assertEmailAttachmentCount($agent, 1);
        $ics = $agent->getAttachments()[0]->getBody();
        self::assertStringContainsString('METHOD:REQUEST', $ics);
        self::assertStringContainsString('BEGIN:VEVENT', $ics);
        self::assertStringContainsString('mailto:'.$contact->getEmail(), $ics);

        // Leaving the visio for another step cancels it: both sides get
        // the cancellation email (a quote follow-up itself stays internal).
        $component->editStep();
        $component->pickStep('quote_sent');
        $component->recallAt = (new \DateTimeImmutable('+3 days'))->format('Y-m-d\TH:i');
        $component->confirmStep();
        self::assertEmailCount(4);
        $cancellation = null;
        foreach (self::getMailerMessages() as $message) {
            if ($message instanceof \Symfony\Component\Mime\Email && str_contains((string) $message->getSubject(), 'annulée')) {
                $cancellation = $message;
            }
        }
        self::assertNotNull($cancellation, 'A cancellation email was sent.');

        // Re-confirming the same quote step sends nothing more.
        $component->editStep();
        $component->confirmStep();
        self::assertEmailCount(4);
    }

    public function testReschedulingAVisioUpdatesWithoutDuplicates(): void
    {
        $contact = $this->persistContact();
        $this->loginAsAdmin();

        $component = $this->mountTwigComponent('Admin:ContactStatusControl', ['contactId' => (int) $contact->getId()]);
        $component->setLiveResponder(new LiveResponder());
        $component->change('in_progress');
        $component->pickStep('visio');
        $component->recallAt = (new \DateTimeImmutable('+2 days 14:00'))->format('Y-m-d\TH:i');
        $component->confirmStep();
        self::assertEmailCount(2);

        // Re-confirming without touching the date: no duplicate emails.
        $component->editStep();
        $component->confirmStep();
        self::assertEmailCount(2);

        // Moving the date re-invites both sides with the new slot.
        $component->editStep();
        $component->recallAt = (new \DateTimeImmutable('+3 days 09:30'))->format('Y-m-d\TH:i');
        $component->confirmStep();
        self::assertEmailCount(4);
        $subjects = array_map(static fn ($m) => $m instanceof \Symfony\Component\Mime\Email ? (string) $m->getSubject() : '', self::getMailerMessages());
        self::assertNotEmpty(array_filter($subjects, static fn (string $s): bool => str_contains($s, 'déplacée')), 'A reschedule email was sent.');

        // Converting the lead keeps the meeting: no cancellation goes
        // out, and the follow-up thread traces the kept slot.
        $component->change('converted');
        self::assertEmailCount(4);
        $events = self::getContainer()->get(\App\Contact\Repository\ContactEventRepository::class)->listForContact((int) $contact->getId());
        $kinds = array_filter(array_map(static fn ($e) => $e->kind, $events));
        self::assertContains('visio_kept', $kinds);
        self::assertContains('visio_planned', $kinds);
        self::assertContains('visio_rescheduled', $kinds);
    }

    public function testAConvertedLeadKeepsItsMeetingReachable(): void
    {
        $contact = $this->persistContact();
        $this->loginAsAdmin();

        $component = $this->mountTwigComponent('Admin:ContactStatusControl', ['contactId' => (int) $contact->getId()]);
        $component->setLiveResponder(new LiveResponder());
        $component->change('in_progress');
        $component->pickStep('visio');
        $slot = new \DateTimeImmutable('+2 days 14:00');
        $component->recallAt = $slot->format('Y-m-d\TH:i');
        $component->confirmStep();

        $component->change('converted');

        // The meeting is deliberately kept on conversion, so the record
        // must keep it too: wiping the step and date left it unreachable
        // (no reassignment sync, no cancellation email, no reminder).
        $this->em->clear();
        $fresh = $this->em->find(Contact::class, $contact->getId());
        self::assertSame(\App\Contact\Domain\NextStep::Visio, $fresh->getNextStep());
        self::assertSame($slot->format('Y-m-d H:i'), $fresh->getRecallAt()?->format('Y-m-d H:i'));

        // Closing it afterwards still cancels properly, which is only
        // possible because the step survived the conversion.
        $component = $this->mountTwigComponent('Admin:ContactStatusControl', ['contactId' => (int) $contact->getId()]);
        $component->setLiveResponder(new LiveResponder());
        $component->change('closed');

        $this->em->clear();
        $fresh = $this->em->find(Contact::class, $contact->getId());
        self::assertNull($fresh->getNextStep(), 'Closing drops the step for good.');
        self::assertNull($fresh->getRecallAt());
        $kinds = array_filter(array_map(
            static fn ($e) => $e->kind,
            self::getContainer()->get(\App\Contact\Repository\ContactEventRepository::class)->listForContact((int) $contact->getId()),
        ));
        self::assertContains('visio_cancelled', $kinds);
    }

    public function testClosingFromTheListCancelsThePlannedVisio(): void
    {
        $contact = $this->persistContact();
        $this->loginAsAdmin();

        $card = $this->mountTwigComponent('Admin:ContactStatusControl', ['contactId' => (int) $contact->getId()]);
        $card->setLiveResponder(new LiveResponder());
        $card->change('in_progress');
        $card->pickStep('visio');
        $card->recallAt = (new \DateTimeImmutable('+2 days'))->format('Y-m-d\TH:i');
        $card->confirmStep();
        self::assertEmailCount(2);

        // Closing from the list quick actions goes through the same visio
        // matrix as the detail card: cancellation notified.
        $list = $this->mountTwigComponent('Admin:ContactList');
        $list->setLiveResponder(new LiveResponder());
        $list->changeStatus((int) $contact->getId(), 'closed');
        self::assertEmailCount(4);
        $subjects = array_map(static fn ($m) => $m instanceof \Symfony\Component\Mime\Email ? (string) $m->getSubject() : '', self::getMailerMessages());
        self::assertNotEmpty(array_filter($subjects, static fn (string $s): bool => str_contains($s, 'annulée')), 'A cancellation email was sent.');
    }

    public function testRecontactNoticeEmailIsSentOnlyWhenTicked(): void
    {
        $contact = $this->persistContact();
        $this->loginAsAdmin();

        $component = $this->mountTwigComponent('Admin:ContactStatusControl', ['contactId' => (int) $contact->getId()]);
        $component->setLiveResponder(new LiveResponder());
        $component->change('in_progress');
        $component->pickStep('recontact');
        $component->pickChannel('whatsapp');
        $component->recallAt = (new \DateTimeImmutable('+2 days 14:00'))->format('Y-m-d\TH:i');
        $component->confirmStep();

        // Checkbox unticked: nothing goes out.
        self::assertEmailCount(0);

        // Ticked: the prospect gets the heads-up, traced in the thread.
        $component->editStep();
        $component->recallAt = (new \DateTimeImmutable('+3 days 10:00'))->format('Y-m-d\TH:i');
        $component->notifyClient = true;
        $component->confirmStep();
        self::assertEmailCount(1);
        $notice = self::getMailerMessage(0);
        self::assertInstanceOf(Email::class, $notice);
        self::assertEmailAddressContains($notice, 'To', (string) $contact->getEmail());
        // Channel-aware subject: who reaches out, how and when, readable
        // from the inbox list alone.
        self::assertStringContainsString('vous écrit sur WhatsApp', (string) $notice->getSubject());
        self::assertEmailHtmlBodyContains($notice, 'heure de Paris');
        self::assertEmailHtmlBodyContains($notice, 'WhatsApp');

        $events = self::getContainer()->get(\App\Contact\Repository\ContactEventRepository::class)->listForContact((int) $contact->getId());
        self::assertContains('recontact_notice', array_filter(array_map(static fn ($e) => $e->kind, $events)));
    }

    public function testRecallAgendaEventIsDroppedWhenTheRecontactNoLongerApplies(): void
    {
        $contact = $this->persistContact();
        $this->loginAsAdmin();

        $component = $this->mountTwigComponent('Admin:ContactStatusControl', ['contactId' => (int) $contact->getId()]);
        $component->setLiveResponder(new LiveResponder());
        $component->change('in_progress');
        $component->pickStep('recontact');
        $component->pickChannel('phone');
        $component->recallAt = (new \DateTimeImmutable('+2 days 14:00'))->format('Y-m-d\TH:i');
        $component->confirmStep();

        // The Calendar API is not configured in tests: simulate the agenda
        // event it would have created, then leave the recontact step.
        $entity = $this->em->find(Contact::class, $contact->getId());
        $entity->setRecallEventId('fake-recall-event');
        $this->em->flush();

        $component->editStep();
        $component->pickStep('quote_preparation');
        $component->confirmStep();

        $this->em->clear();
        self::assertNull(
            $this->em->find(Contact::class, $contact->getId())->getRecallEventId(),
            'Leaving the recontact step drops the mirrored agenda event.',
        );
    }

    public function testRecallAgendaEventIsDroppedWhenTheLeadCloses(): void
    {
        $contact = $this->persistContact();
        $this->loginAsAdmin();

        $component = $this->mountTwigComponent('Admin:ContactStatusControl', ['contactId' => (int) $contact->getId()]);
        $component->setLiveResponder(new LiveResponder());
        $component->change('in_progress');
        $component->pickStep('recontact');
        $component->pickChannel('phone');
        $component->recallAt = (new \DateTimeImmutable('+2 days 14:00'))->format('Y-m-d\TH:i');
        $component->confirmStep();

        $entity = $this->em->find(Contact::class, $contact->getId());
        $entity->setRecallEventId('fake-recall-event');
        $this->em->flush();

        $component->change('closed');

        $this->em->clear();
        self::assertNull(
            $this->em->find(Contact::class, $contact->getId())->getRecallEventId(),
            'Closing the lead drops the mirrored agenda event.',
        );
    }

    public function testClientEmailsCarryTheAssignedAgentFirstName(): void
    {
        $contact = $this->persistContact();
        $this->loginAsAdmin();

        // Assign the lead: the client emails should name this agent.
        $admin = self::getContainer()->get('security.token_storage')->getToken()?->getUser();
        \assert($admin instanceof User);
        $entity = $this->em->find(Contact::class, $contact->getId());
        $entity->setAssignedTo($admin);
        $this->em->flush();

        $component = $this->mountTwigComponent('Admin:ContactStatusControl', ['contactId' => (int) $contact->getId()]);
        $component->setLiveResponder(new LiveResponder());
        $component->change('in_progress');
        $component->pickStep('recontact');
        $component->pickChannel('phone');
        $component->recallAt = (new \DateTimeImmutable('+2 days 14:00'))->format('Y-m-d\TH:i');
        $component->notifyClient = true;
        $component->confirmStep();

        self::assertEmailCount(1);
        $notice = self::getMailerMessage(0);
        self::assertInstanceOf(Email::class, $notice);
        $firstName = (string) $admin->getFirstName();
        self::assertStringContainsString($firstName, (string) $notice->getSubject());
        self::assertStringContainsString('vous rappelle', (string) $notice->getSubject());
        self::assertEmailHtmlBodyContains($notice, $firstName);
    }

    public function testOverdueVisioCanBeClosedAsDoneOrNoShow(): void
    {
        $contact = $this->persistContact();
        $this->loginAsAdmin();

        $component = $this->mountTwigComponent('Admin:ContactStatusControl', ['contactId' => (int) $contact->getId()]);
        $component->setLiveResponder(new LiveResponder());
        $component->change('in_progress');
        $component->pickStep('visio');
        $component->recallAt = (new \DateTimeImmutable('+2 days 14:00'))->format('Y-m-d\TH:i');
        $component->confirmStep();

        // "Visio faite" : l'étape se clôt, l'éditeur rouvre pour la suite.
        $component->visioDone();
        self::assertTrue($component->editingStep);
        self::assertSame('', $component->pendingStep);
        $this->em->clear();
        $reloaded = $this->em->find(Contact::class, $contact->getId());
        self::assertNull($reloaded->getNextStep());
        self::assertNull($reloaded->getRecallAt());

        $events = self::getContainer()->get(\App\Contact\Repository\ContactEventRepository::class)->listForContact((int) $contact->getId());
        self::assertContains('visio_done', array_filter(array_map(static fn ($e) => $e->kind, $events)));

        // No-show : replanification préremplie sur visio, date vide.
        $component->pickStep('visio');
        $component->recallAt = (new \DateTimeImmutable('+3 days 09:00'))->format('Y-m-d\TH:i');
        $component->confirmStep();
        $component->visioNoShow();
        self::assertTrue($component->editingStep);
        self::assertSame('visio', $component->pendingStep);
        self::assertSame('', $component->recallAt);
        $events = self::getContainer()->get(\App\Contact\Repository\ContactEventRepository::class)->listForContact((int) $contact->getId());
        self::assertContains('visio_noshow', array_filter(array_map(static fn ($e) => $e->kind, $events)));
    }

    public function testFirstTreatmentPromptsForARating(): void
    {
        $contact = $this->persistContact();
        $this->loginAsAdmin();

        $quality = $this->mountTwigComponent('Admin:LeadQuality', ['contactId' => (int) $contact->getId()]);
        $quality->setLiveResponder(new LiveResponder());

        // First treatment fires the nudge (no rating yet).
        $quality->onFirstTreatment();
        self::assertTrue($quality->promptRating);

        // Rating turns it off; a later first-treated event on a rated lead
        // must not nudge again.
        $quality->rate(4);
        self::assertFalse($quality->promptRating);
        $quality->onFirstTreatment();
        self::assertFalse($quality->promptRating);

        // The repository reports the first treatment exactly once.
        $fresh = $this->persistContact();
        $repo = self::getContainer()->get(\App\Contact\Repository\ContactRepository::class);
        self::assertTrue($repo->updateStatus((int) $fresh->getId(), \App\Contact\Domain\ContactStatus::InProgress));
        self::assertFalse($repo->updateStatus((int) $fresh->getId(), \App\Contact\Domain\ContactStatus::Closed));
    }

    public function testUnknownClosureReasonIsRejected(): void
    {
        $contact = $this->persistContact();
        $this->loginAsAdmin();

        $component = $this->mountTwigComponent('Admin:ContactStatusControl', ['contactId' => (int) $contact->getId()]);
        $component->setLiveResponder(new LiveResponder());

        $this->expectException(BadRequestHttpException::class);
        $component->chooseClosureReason('nope');
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
        self::assertStringContainsString('Lead clôturé', $html);
        self::assertStringContainsString('Profil inadapté à nos services', $html);

        $contact->setStatus(\App\Contact\Domain\ContactStatus::Converted);
        $this->em->flush();

        $html = (string) $this->renderTwigComponent('Admin:ContactTerminalBanner', ['contactId' => (int) $contact->getId()]);
        self::assertStringContainsString('data-testid="terminal-banner"', $html);
        self::assertStringContainsString('Lead converti en client', $html);
        self::assertStringNotContainsString('Profil inadapté', $html, 'Closure reason is closed-only.');
    }

    public function testTheTerminalBannerNamesWhoClosedTheLead(): void
    {
        $contact = $this->persistContact();
        $this->loginAsAdmin();

        $component = $this->mountTwigComponent('Admin:ContactStatusControl', ['contactId' => (int) $contact->getId()]);
        $component->setLiveResponder(new LiveResponder());
        $component->change('closed');

        $html = (string) $this->renderTwigComponent('Admin:ContactTerminalBanner', ['contactId' => (int) $contact->getId()]);
        self::assertStringContainsString('data-testid="terminal-banner-author"', $html);
        // Le nom vient du fil de suivi, capturé au moment du changement.
        self::assertStringContainsString('First Last', $html);
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
        $component->propertyType = ' t2 , loft ';
        $component->save();

        $this->em->clear();
        $reloaded = $this->em->find(Contact::class, $contact->getId());
        self::assertSame(2200, $reloaded->getProjectBudget());
        self::assertSame('11e, 18e', $reloaded->getProjectAreas());
        self::assertSame('2026-09-01', $reloaded->getProjectMoveInAt()?->format('Y-m-d'));
        self::assertSame('t2,loft', $reloaded->getProjectPropertyType());

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
            // Always in the past, whatever the time of day the suite runs:
            // the reception entry must sort before status events.
            ->setCreatedAt(new \DateTimeImmutable('-1 day 10:00'));
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
