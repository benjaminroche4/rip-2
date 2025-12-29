<?php

namespace App\Controller\Public;

use App\Entity\PropertyEstimation;
use App\Form\PropertyEstimationType;
use Presta\SitemapBundle\Sitemap\Url\UrlConcrete;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\UX\Turbo\TurboBundle;

final class PropertyEstimationController extends AbstractController
{
    #[Route(
        path: [
            'fr' => '/{_locale}/services/pour-les-proprietaires',
            'en' => '/{_locale}/services/for-landlords',
        ],
        name: 'app_service_landlords',
        options: [
            'sitemap' =>
                [
                    'priority' => 0.8,
                    'changefreq' => UrlConcrete::CHANGEFREQ_WEEKLY,
                    'lastmod' => new \DateTime('2025-10-09')
                ]
        ]
    )]
    public function index(Request $request): Response
    {
        $form = $this->createForm(PropertyEstimationType::class, new PropertyEstimation());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $task = $form->getData();
            $task->setCreatedAt(new \DateTimeImmutable());
            $task->setIp($request->getClientIp());

            if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
                $request->setRequestFormat(TurboBundle::STREAM_FORMAT);
                return $this->renderBlock('public/property_estimation/task.html.twig', 'success_stream', ['task' => $task]);
            }

            return $this->redirectToRoute('task_success', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('public/property_estimation/index.html.twig', [
            'form' => $form,
        ]);
    }
}
