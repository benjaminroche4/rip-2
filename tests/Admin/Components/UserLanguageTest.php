<?php

namespace App\Tests\Admin\Components;

use App\Admin\Twig\Components\UserLanguage;
use App\Auth\Domain\Language;
use App\Auth\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * Admin:UserLanguage behaviour on the user profile page: per-chip autosave
 * of the user's language, admin-only.
 */
final class UserLanguageTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@user-language-test.local')->execute();
    }

    public function testChooseLanguagePersistsTheSelectedLocaleAndClosesTheEditor(): void
    {
        $this->loginAsAdmin();
        $target = $this->seedUser('operator@user-language-test.local');

        $component = $this->mount($target);
        $component->startEditing();
        $component->chooseLanguage('en');

        $this->em->refresh($target);
        self::assertSame(Language::En, $target->getLanguage());
        self::assertSame(Language::En, $component->getLanguage());
        // Saving closes the editor: the tile goes back to read-only.
        self::assertFalse($component->editing);
    }

    public function testLanguageCannotChangeWhileTheEditorIsClosed(): void
    {
        $this->loginAsAdmin();
        $target = $this->seedUser('operator@user-language-test.local');
        $target->setLanguage(Language::Fr);
        $this->em->flush();

        $component = $this->mount($target);

        // A forged call without opening the pencil first changes nothing.
        try {
            $component->chooseLanguage('en');
            self::fail('Choosing a language with the editor closed must be denied.');
        } catch (AccessDeniedException) {
        }

        $this->em->refresh($target);
        self::assertSame(Language::Fr, $target->getLanguage());
    }

    public function testCancelClosesTheEditorWithoutChanging(): void
    {
        $this->loginAsAdmin();
        $target = $this->seedUser('operator@user-language-test.local');
        $target->setLanguage(Language::Fr);
        $this->em->flush();

        $component = $this->mount($target);
        $component->startEditing();
        $component->cancelEditing();

        self::assertFalse($component->editing);
        $this->em->refresh($target);
        self::assertSame(Language::Fr, $target->getLanguage());
    }

    public function testChooseLanguageRejectsAnUnknownLocale(): void
    {
        $this->loginAsAdmin();
        $target = $this->seedUser('operator@user-language-test.local');

        $component = $this->mount($target);
        $component->startEditing();

        $this->expectException(NotFoundHttpException::class);
        $component->chooseLanguage('de');
    }

    public function testTileIsReadOnlyUntilThePencilIsClicked(): void
    {
        $this->loginAsAdmin();
        $target = $this->seedUser('operator@user-language-test.local');
        $target->setLanguage(Language::Fr);
        $this->em->flush();

        // Closed: the stored locale is shown, no chip to click.
        $closed = (string) $this->renderTwigComponent('Admin:UserLanguage', ['userId' => (int) $target->getId()]);
        self::assertStringContainsString('data-testid="user-language-value"', $closed);
        self::assertStringContainsString('data-testid="user-language-edit"', $closed);
        self::assertStringNotContainsString('data-testid="user-language-fr"', $closed);

        // Opened: one chip per locale, the stored one active.
        $open = (string) $this->renderTwigComponent('Admin:UserLanguage', [
            'userId' => (int) $target->getId(),
            'editing' => true,
        ]);
        self::assertStringContainsString('data-testid="user-language-fr"', $open);
        self::assertStringContainsString('data-testid="user-language-en"', $open);
        self::assertStringContainsString('data-testid="user-language-cancel"', $open);
        self::assertSame(1, substr_count($open, 'aria-pressed="true"'));
    }

    public function testStaffCanChangeTheirOwnLanguage(): void
    {
        // "Mon profil": no ROLE_ADMIN needed to pick one's own locale.
        $staff = $this->seedUser('self@user-language-test.local', ['ROLE_SECTION_VISITS']);
        $this->loginAs($staff);

        $component = $this->mount($staff);
        $component->startEditing();
        $component->chooseLanguage('en');

        $this->em->refresh($staff);
        self::assertSame(Language::En, $staff->getLanguage());
    }

    public function testStaffCannotChangeSomeoneElseLanguage(): void
    {
        $staff = $this->seedUser('self@user-language-test.local', ['ROLE_SECTION_VISITS']);
        $other = $this->seedUser('operator@user-language-test.local');
        $this->loginAs($staff);

        $this->expectException(AccessDeniedException::class);
        $this->mount($other);
    }

    public function testNonAdminCannotMountTheComponent(): void
    {
        $user = $this->seedUser('plain@user-language-test.local');
        $this->loginAs($user);

        $this->expectException(AccessDeniedException::class);
        $this->mount($user);
    }

    private function mount(User $target): UserLanguage
    {
        /** @var UserLanguage $component */
        $component = $this->mountTwigComponent('Admin:UserLanguage', ['userId' => (int) $target->getId()]);

        return $component;
    }

    private function loginAsAdmin(): User
    {
        $admin = $this->seedUser('admin@user-language-test.local', ['ROLE_ADMIN']);
        $this->loginAs($admin);

        return $admin;
    }

    /**
     * @param list<string> $roles
     */
    private function seedUser(string $email, array $roles = []): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setFirstName('First')
            ->setLastName('Last')
            ->setRoles($roles)
            ->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)
            ->setVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function loginAs(User $user): void
    {
        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken($user, 'main', $user->getRoles()),
        );
    }
}
