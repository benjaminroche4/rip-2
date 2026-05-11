<?php

declare(strict_types=1);

namespace App\Tests\Admin\Components;

use App\Auth\Entity\ResetPasswordRequest;
use App\Auth\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * Locks the PaymentList LiveComponent contract. Stripe is not configured in
 * the test environment (STRIPE_SECRET_KEY is unset/empty), so the repo
 * returns an empty list — perfect for testing the empty-state branch and
 * the ROLE_ADMIN guard without mocking the SDK.
 */
final class PaymentListTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');

        $this->em->createQuery('DELETE FROM '.ResetPasswordRequest::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class)->execute();
    }

    public function testRendersEmptyStateWhenStripeReturnsNothing(): void
    {
        $this->seedAdmin('admin@example.com');
        $this->em->flush();
        $this->loginAs('admin@example.com');

        $component = $this->mountTwigComponent('Admin:PaymentList', ['currencySymbol' => '€']);

        self::assertTrue($component->isEmpty());
        self::assertFalse($component->hasMore());
        self::assertSame(0, $component->getTotalCount());
        self::assertSame([], $component->getItems());

        $html = (string) $this->renderTwigComponent('Admin:PaymentList', ['currencySymbol' => '€']);
        self::assertStringNotContainsString('data-testid="payments-load-more"', $html);
    }

    public function testNonAdminCannotMountTheComponent(): void
    {
        $this->seedUser('user@example.com');
        $this->em->flush();
        $this->loginAs('user@example.com');

        $this->expectException(AccessDeniedException::class);
        $this->mountTwigComponent('Admin:PaymentList', ['currencySymbol' => '€']);
    }

    public function testStripeDashboardUrlIsExposed(): void
    {
        $this->seedAdmin('admin@example.com');
        $this->em->flush();
        $this->loginAs('admin@example.com');

        $component = $this->mountTwigComponent('Admin:PaymentList', ['currencySymbol' => '€']);

        self::assertSame('https://dashboard.stripe.com/payments', $component->getStripeDashboardUrl());
    }

    private function seedAdmin(string $email): User
    {
        return $this->seedUser($email, ['ROLE_ADMIN']);
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
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($user);

        return $user;
    }

    private function loginAs(string $email): void
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user);

        $token = new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken($user, 'main', $user->getRoles());
        self::getContainer()->get('security.token_storage')->setToken($token);
    }
}
