<?php

declare(strict_types=1);

namespace App\RealEstateAgent\Controller;

use App\RealEstateAgent\Repository\AgencyRepository;
use App\RealEstateAgent\Repository\RealEstateAgentRepository;
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
final class AgentController extends AbstractController
{
    public function __construct(
        #[Autowire('%admin_path_prefix%')]
        private readonly string $adminPathPrefix,
    ) {
    }

    #[Route(
        path: [
            'fr' => '/agents-immobiliers',
            'en' => '/real-estate-agents',
        ],
        name: 'agents',
        methods: ['GET'],
    )]
    public function index(string $adminPrefix): Response
    {
        $this->ensureValidPrefix($adminPrefix);

        // The list itself lives in the RealEstateAgent:AgentList live component.
        return $this->render('admin/agents/index.html.twig', [
            'adminPrefix' => $adminPrefix,
        ]);
    }

    #[Route(
        path: [
            'fr' => '/agents-immobiliers/nouveau',
            'en' => '/real-estate-agents/new',
        ],
        name: 'agent_new',
        methods: ['GET'],
    )]
    public function newAgent(string $adminPrefix): Response
    {
        $this->ensureValidPrefix($adminPrefix);

        // The form itself lives in the RealEstateAgent:AgentCreate live component.
        return $this->render('admin/agents/new_agent.html.twig', [
            'adminPrefix' => $adminPrefix,
        ]);
    }

    #[Route(
        path: [
            'fr' => '/agents-immobiliers/nouvelle-agence',
            'en' => '/real-estate-agents/new-agency',
        ],
        name: 'agency_new',
        methods: ['GET'],
    )]
    public function newAgency(string $adminPrefix): Response
    {
        $this->ensureValidPrefix($adminPrefix);

        // The form itself lives in the RealEstateAgent:AgencyCreate live component.
        return $this->render('admin/agents/new_agency.html.twig', [
            'adminPrefix' => $adminPrefix,
        ]);
    }

    #[Route(
        path: [
            'fr' => '/agents-immobiliers/{id}',
            'en' => '/real-estate-agents/{id}',
        ],
        name: 'agent_show',
        requirements: ['id' => '\d+'],
        methods: ['GET'],
    )]
    public function showAgent(string $adminPrefix, int $id, RealEstateAgentRepository $agents): Response
    {
        $this->ensureValidPrefix($adminPrefix);

        $agent = $agents->findDetail($id);
        if (null === $agent) {
            throw $this->createNotFoundException();
        }

        return $this->render('admin/agents/show_agent.html.twig', [
            'adminPrefix' => $adminPrefix,
            'agent' => $agent,
        ]);
    }

    #[Route(
        path: [
            'fr' => '/agents-immobiliers/agences/{id}',
            'en' => '/real-estate-agents/agencies/{id}',
        ],
        name: 'agency_show',
        requirements: ['id' => '\d+'],
        methods: ['GET'],
    )]
    public function showAgency(string $adminPrefix, int $id, AgencyRepository $agencies, RealEstateAgentRepository $agents): Response
    {
        $this->ensureValidPrefix($adminPrefix);

        $agency = $agencies->findDetail($id);
        if (null === $agency) {
            throw $this->createNotFoundException();
        }

        return $this->render('admin/agents/show_agency.html.twig', [
            'adminPrefix' => $adminPrefix,
            'agency' => $agency,
            'agents' => $agents->findSummariesByAgency($agency->id),
        ]);
    }

    private function ensureValidPrefix(string $adminPrefix): void
    {
        if (!hash_equals($this->adminPathPrefix, $adminPrefix)) {
            throw $this->createNotFoundException();
        }
    }
}
