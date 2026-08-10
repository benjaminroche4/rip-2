<?php

declare(strict_types=1);

namespace App\Visit\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

// Same security model as other admin controllers: access_control on the
// prefix in security.yaml + hash_equals here. A wrong-but-format-valid
// prefix returns 404 before triggering any auth challenge.
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
final class VisitController extends AbstractController
{
    public function __construct(
        #[Autowire('%admin_path_prefix%')]
        private readonly string $adminPathPrefix,
    ) {
    }

    #[Route(
        path: [
            'fr' => '/visites',
            'en' => '/visits',
        ],
        name: 'visits',
        methods: ['GET'],
    )]
    public function index(string $adminPrefix): Response
    {
        $this->ensureValidPrefix($adminPrefix);

        return $this->render('admin/visits/index.html.twig', [
            'adminPrefix' => $adminPrefix,
        ]);
    }

    #[Route(
        path: [
            'fr' => '/visites/nouvelle',
            'en' => '/visits/new',
        ],
        name: 'visit_new',
        methods: ['GET'],
    )]
    public function new(string $adminPrefix): Response
    {
        $this->ensureValidPrefix($adminPrefix);

        // The split form/summary screen lives in the Visit:VisitForm component.
        return $this->render('admin/visits/new.html.twig', [
            'adminPrefix' => $adminPrefix,
        ]);
    }

    private function ensureValidPrefix(string $adminPrefix): void
    {
        if (!hash_equals($this->adminPathPrefix, $adminPrefix)) {
            throw $this->createNotFoundException();
        }
    }
}
