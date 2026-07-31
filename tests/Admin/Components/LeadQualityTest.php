<?php

declare(strict_types=1);

namespace App\Tests\Admin\Components;

use App\Auth\Entity\User;
use App\Contact\Domain\RecontactChannel;
use App\Contact\Entity\Contact;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\LiveResponder;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class LeadQualityTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.Contact::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@leadq-test.local')->execute();
    }

    public function testClickingAStarPersistsTheRating(): void
    {
        $contact = $this->persistContact();
        $this->seedAdmin();
        $this->loginAs('admin@leadq-test.local');

        $component = $this->mountTwigComponent('Admin:LeadQuality', ['contactId' => (int) $contact->getId()]);
        $component->setLiveResponder(new LiveResponder());
        $component->rate(4);

        $this->em->clear();
        self::assertSame(4, $this->em->find(Contact::class, $contact->getId())->getLeadRating());
    }

    public function testOutOfRangeRatingIsRejected(): void
    {
        $contact = $this->persistContact();
        $this->seedAdmin();
        $this->loginAs('admin@leadq-test.local');

        $component = $this->mountTwigComponent('Admin:LeadQuality', ['contactId' => (int) $contact->getId()]);
        $component->setLiveResponder(new LiveResponder());

        $this->expectException(BadRequestHttpException::class);
        $component->rate(6);
    }

    public function testSavingTheNotePersistsTrimmedText(): void
    {
        $contact = $this->persistContact();
        $this->seedAdmin();
        $this->loginAs('admin@leadq-test.local');

        $component = $this->mountTwigComponent('Admin:LeadQuality', ['contactId' => (int) $contact->getId()]);
        $component->setLiveResponder(new LiveResponder());
        $component->note = '  Bon profil, budget OK  ';
        $component->saveNote();

        $this->em->clear();
        self::assertSame('Bon profil, budget OK', $this->em->find(Contact::class, $contact->getId())->getLeadNote());
    }

    public function testSettingRecontactChannelPersists(): void
    {
        $contact = $this->persistContact();
        $this->seedAdmin();
        $this->loginAs('admin@leadq-test.local');

        $component = $this->mountTwigComponent('Admin:LeadQuality', ['contactId' => (int) $contact->getId()]);
        $component->setLiveResponder(new LiveResponder());
        $component->setChannel('whatsapp');

        $this->em->clear();
        self::assertSame(RecontactChannel::Whatsapp, $this->em->find(Contact::class, $contact->getId())->getRecontactChannel());
    }

    public function testUnknownChannelIsRejected(): void
    {
        $contact = $this->persistContact();
        $this->seedAdmin();
        $this->loginAs('admin@leadq-test.local');

        $component = $this->mountTwigComponent('Admin:LeadQuality', ['contactId' => (int) $contact->getId()]);
        $component->setLiveResponder(new LiveResponder());

        $this->expectException(BadRequestHttpException::class);
        $component->setChannel('pigeon');
    }

    public function testExistingNotePrefillsTheField(): void
    {
        $contact = $this->persistContact()->setLeadNote('Déjà qualifié');
        $this->seedAdmin();
        $this->loginAs('admin@leadq-test.local');

        $component = $this->mountTwigComponent('Admin:LeadQuality', ['contactId' => (int) $contact->getId()]);
        $component->setLiveResponder(new LiveResponder());

        self::assertSame('Déjà qualifié', $component->note);
    }

    public function testNonAdminCannotMount(): void
    {
        $user = (new User())
            ->setEmail('user@leadq-test.local')
            ->setFirstName('First')->setLastName('Last')
            ->setRoles([])->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($user);
        $this->em->flush();
        $this->loginAs('user@leadq-test.local');

        $this->expectException(AccessDeniedException::class);
        $this->mountTwigComponent('Admin:LeadQuality', ['contactId' => 1]);
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

    private function seedAdmin(): void
    {
        $admin = (new User())
            ->setEmail('admin@leadq-test.local')
            ->setFirstName('First')->setLastName('Last')
            ->setRoles(['ROLE_ADMIN'])->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($admin);
        $this->em->flush();
    }

    private function loginAs(string $email): void
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user);
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }
}
