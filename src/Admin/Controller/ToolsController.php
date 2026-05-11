<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Admin\Entity\DocumentRequest;
use App\Admin\Service\DocumentRequestPdfRenderer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\HeaderUtils;
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
final class ToolsController extends AbstractController
{
    public function __construct(
        #[Autowire('%admin_path_prefix%')]
        private readonly string $adminPathPrefix,
    ) {
    }

    #[Route(
        path: [
            'fr' => '/outils',
            'en' => '/tools',
        ],
        name: 'tools',
        methods: ['GET'],
    )]
    public function index(string $adminPrefix): Response
    {
        $this->ensureValidPrefix($adminPrefix);

        return $this->render('admin/tools/index.html.twig', [
            'adminPrefix' => $adminPrefix,
        ]);
    }

    #[Route(
        path: [
            'fr' => '/outils/documents',
            'en' => '/tools/documents',
        ],
        name: 'tools_documents',
        methods: ['GET'],
    )]
    public function documents(string $adminPrefix): Response
    {
        $this->ensureValidPrefix($adminPrefix);

        return $this->render('admin/tools/documents/index.html.twig', [
            'adminPrefix' => $adminPrefix,
        ]);
    }

    #[Route(
        path: [
            'fr' => '/outils/documents/catalogue',
            'en' => '/tools/documents/catalogue',
        ],
        name: 'tools_documents_catalogue',
        methods: ['GET'],
    )]
    public function documentsCatalogue(string $adminPrefix): Response
    {
        $this->ensureValidPrefix($adminPrefix);

        return $this->render('admin/tools/documents/catalogue.html.twig', [
            'adminPrefix' => $adminPrefix,
        ]);
    }

    #[Route(
        path: [
            'fr' => '/outils/documents/demande',
            'en' => '/tools/documents/request',
        ],
        name: 'tools_documents_request',
        methods: ['GET'],
    )]
    public function documentsRequest(string $adminPrefix): Response
    {
        $this->ensureValidPrefix($adminPrefix);

        return $this->render('admin/tools/documents/request.html.twig', [
            'adminPrefix' => $adminPrefix,
        ]);
    }

    /**
     * Serves the generated PDF for a saved DocumentRequest. Locked behind the
     * admin prefix + ROLE_ADMIN, identified by id. Content-Disposition is set
     * to attachment so navigating here triggers a download instead of opening
     * the PDF inline — which is what the form's download_trigger Stimulus
     * controller relies on.
     */
    #[Route(
        path: [
            'fr' => '/outils/documents/demande/{id}/pdf',
            'en' => '/tools/documents/request/{id}/pdf',
        ],
        name: 'tools_documents_request_pdf',
        requirements: ['id' => '\d+'],
        methods: ['GET'],
    )]
    public function documentsRequestPdf(
        string $adminPrefix,
        DocumentRequest $request,
        DocumentRequestPdfRenderer $renderer,
    ): Response {
        $this->ensureValidPrefix($adminPrefix);
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $pdf = $renderer->render($request);

        $response = new Response($pdf);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $renderer->filename($request),
        ));

        return $response;
    }

    private function ensureValidPrefix(string $adminPrefix): void
    {
        if (!hash_equals($this->adminPathPrefix, $adminPrefix)) {
            throw $this->createNotFoundException();
        }
    }
}
