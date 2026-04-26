<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Contact\Repository\ContactRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

// NOTE: pas de #[IsGranted('ROLE_ADMIN')] ici. La sécurité passe par
// access_control dans security.yaml, ancré sur la vraie valeur de
// %admin_path_prefix%. Avec IsGranted au niveau controller, un mauvais
// prefix de format valide déclencherait un login redirect anonyme — ce qui
// révélerait le pattern de l'admin. Avec access_control + hash_equals dans
// le controller, un mauvais prefix tombe en 404 avant tout challenge auth.
#[Route(
    path: [
        'fr' => '/{_locale}/{adminPrefix}/admin',
        'en' => '/{_locale}/{adminPrefix}/admin',
    ],
    name: 'admin_',
    requirements: [
        '_locale' => 'fr|en',
        'adminPrefix' => '[a-zA-Z0-9_-]{16,64}',
    ],
)]
final class DashboardController extends AbstractController
{
    public function __construct(
        #[Autowire('%admin_path_prefix%')]
        private readonly string $adminPathPrefix,
        private readonly ContactRepository $contactRepository,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'dashboard', methods: ['GET'])]
    public function index(string $adminPrefix, Request $request): Response
    {
        $this->ensureValidPrefix($adminPrefix);

        $contactsByMonth = $this->contactRepository->countByMonth(12);

        return $this->render('admin/dashboard/index.html.twig', [
            'adminPrefix' => $adminPrefix,
            'contactsChartLabels' => array_map(
                fn (array $row) => $this->formatYmLabel($row['ym'], $request->getLocale()),
                $contactsByMonth,
            ),
            'contactsChartData' => array_map(
                static fn (array $row) => $row['count'],
                $contactsByMonth,
            ),
            'contactsChartLabel' => $this->translator->trans('admin.dashboard.contactsMonthly.label'),
        ]);
    }

    /**
     * "2026-04" → "avril 2026" (fr) / "April 2026" (en).
     */
    private function formatYmLabel(string $ym, string $locale): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m', $ym);
        if (false === $date) {
            return $ym;
        }
        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, null, null, 'MMM yyyy');

        return $formatter->format($date) ?: $ym;
    }

    /**
     * Compares the URL prefix to the configured secret in constant time.
     * Throws 404 (not 403) on mismatch to avoid leaking the existence
     * of the admin space on a wrong-but-plausible URL.
     */
    private function ensureValidPrefix(string $adminPrefix): void
    {
        if (!hash_equals($this->adminPathPrefix, $adminPrefix)) {
            throw $this->createNotFoundException();
        }
    }
}
