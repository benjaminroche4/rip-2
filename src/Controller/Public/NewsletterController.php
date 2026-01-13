<?php

namespace App\Controller\Public;

use Doctrine\ORM\EntityManagerInterface;
use Presta\SitemapBundle\Sitemap\Url\UrlConcrete;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final class NewsletterController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
    )
    {
    }

    #[Route(
        path: [
            'fr' => '/{_locale}/newsletter/rejoindre',
            'en' => '/{_locale}/newsletter/join',
        ],
        name: 'app_newsletter',
        options: [
            'sitemap' =>
                [
                    'priority' => 0.4,
                    'changefreq' => UrlConcrete::CHANGEFREQ_MONTHLY,
                    'lastmod' => new \DateTime('2026-01-04')
                ]
        ]
    )]
    public function index(): Response
    {
        return $this->render('public/newsletter/index.html.twig');
    }
}
