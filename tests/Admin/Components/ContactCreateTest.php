<?php

declare(strict_types=1);

namespace App\Tests\Admin\Components;

use App\Auth\Entity\User;
use App\Contact\Domain\ContactSource;
use App\Contact\Entity\Contact;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class ContactCreateTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.Contact::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@create-test.local')->execute();
        $this->loginAsAdmin();
    }

    public function testCreatesAManualContactAndRedirectsToItsPage(): void
    {
        $component = $this->mountTwigComponent('Admin:ContactCreate', ['adminPrefix' => 'test_admin_prefix_1234567890abcdef']);
        $component->firstName = ' marie ';
        $component->lastName = 'curie';
        $component->email = 'marie@example.com';
        $component->phoneNumber = '+33611223344';
        $component->chooseLang('en');
        $component->chooseHelpType('contact.contactForm.helpType.choice.1');
        $component->chooseOffer('confie');
        $component->chooseSource('whatsapp');
        $component->message = 'Appel du 31/07, cherche un T2.';

        $response = $component->create();
        self::assertInstanceOf(RedirectResponse::class, $response);

        $contact = $this->em->getRepository(Contact::class)->findOneBy(['email' => 'marie@example.com']);
        self::assertNotNull($contact);
        self::assertSame('marie', $contact->getFirstName());
        self::assertSame('en', $contact->getLang());
        self::assertSame('confie', $contact->getOffer());
        self::assertSame(ContactSource::Whatsapp, $contact->getSource());
        self::assertSame('Appel du 31/07, cherche un T2.', $contact->getMessage());
        self::assertNull($contact->getIp(), 'No IP on a manual intake.');
        self::assertStringContainsString($contact->getReference(), (string) $response->getTargetUrl());
        self::assertStringContainsString('test_admin_prefix_1234567890abcdef', (string) $response->getTargetUrl());
    }

    public function testInvalidFieldsBlockCreation(): void
    {
        $component = $this->mountTwigComponent('Admin:ContactCreate', ['adminPrefix' => 'test_admin_prefix_1234567890abcdef']);
        $component->firstName = '';
        $component->email = 'nope';

        self::assertNull($component->create());
        self::assertArrayHasKey('firstName', $component->errors);
        self::assertArrayHasKey('email', $component->errors);
        self::assertSame(0, (int) $this->em->getRepository(Contact::class)->count([]));
    }

    public function testFormSourceCannotBePickedManually(): void
    {
        $component = $this->mountTwigComponent('Admin:ContactCreate', ['adminPrefix' => 'test_admin_prefix_1234567890abcdef']);

        $this->expectException(BadRequestHttpException::class);
        $component->chooseSource('form');
    }

    private function loginAsAdmin(): void
    {
        $admin = (new User())
            ->setEmail(bin2hex(random_bytes(4)).'@create-test.local')
            ->setFirstName('First')->setLastName('Last')
            ->setRoles(['ROLE_ADMIN'])->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($admin);
        $this->em->flush();

        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($admin, 'main', $admin->getRoles()));
    }
}
