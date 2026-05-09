<?php

declare(strict_types=1);

namespace App\Admin\Twig\Components;

use App\Admin\Domain\PaymentRow;
use App\Admin\Repository\StripePaymentRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Paginated payments table backed by the cached Stripe recent payments.
 * Renders behind the admin firewall in the parent template, but LiveActions
 * are reachable on the public /_components/... route — so we re-check
 * ROLE_ADMIN on every mount and action call.
 *
 * Pagination strategy: the repository already caches up to MAX_ITEMS recent
 * payments (5-min TTL). We slice in-process per page rather than calling the
 * Stripe API per-page, which keeps "Voir plus" essentially free.
 */
#[AsLiveComponent(name: 'Admin:PaymentList', template: 'components/Admin/PaymentList.html.twig')]
final class PaymentList
{
    use DefaultActionTrait;

    private const PER_PAGE = 25;
    private const MAX_ITEMS = 100;
    public const STRIPE_DASHBOARD_URL = 'https://dashboard.stripe.com/payments';

    #[LiveProp]
    public int $page = 1;

    #[LiveProp]
    public string $currencySymbol = '';

    /** @var list<PaymentRow>|null */
    private ?array $allCache = null;

    public function __construct(
        private readonly StripePaymentRepository $repository,
        private readonly Security $security,
    ) {
    }

    public function mount(): void
    {
        $this->ensureAdmin();
    }

    #[LiveAction]
    public function more(): void
    {
        $this->ensureAdmin();
        ++$this->page;
    }

    /**
     * @return list<PaymentRow>
     */
    public function getItems(): array
    {
        return array_slice($this->getAllPayments(), 0, $this->page * self::PER_PAGE);
    }

    public function hasMore(): bool
    {
        return $this->getTotalCount() > $this->page * self::PER_PAGE;
    }

    public function getTotalCount(): int
    {
        return \count($this->getAllPayments());
    }

    public function isEmpty(): bool
    {
        return [] === $this->getAllPayments();
    }

    /**
     * Whether we've reached the cap of locally-cached recent payments. The
     * template uses this to invite the user to open Stripe directly when
     * they want to look further back than the cap.
     */
    public function isCapped(): bool
    {
        return self::MAX_ITEMS === $this->getTotalCount();
    }

    public function getStripeDashboardUrl(): string
    {
        return self::STRIPE_DASHBOARD_URL;
    }

    /**
     * @return list<PaymentRow>
     */
    private function getAllPayments(): array
    {
        return $this->allCache ??= $this->repository->recentPayments(self::MAX_ITEMS);
    }

    private function ensureAdmin(): void
    {
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            throw new AccessDeniedException('Admin access required.');
        }
    }
}
