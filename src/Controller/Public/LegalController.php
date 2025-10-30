<?php

namespace App\Controller\Public;

use Presta\SitemapBundle\Sitemap\Url\UrlConcrete;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LegalController extends AbstractController
{
    #[Route(
        path: [
            'fr' => '/{_locale}/mentions-legales',
            'en' => '/{_locale}/legal-notice'
        ],
        name: 'app_legal',
        options: [
            'sitemap' =>
                [
                    'priority' => 0.1,
                    'changefreq' => UrlConcrete::CHANGEFREQ_MONTHLY,
                    'lastmod' => new \DateTime('2025-10-30')
                ]
        ]
    )]
    public function legalNotice(): Response
    {
        return $this->render('public/legal/legal_notice.html.twig');
    }

    #[Route(
        path: [
            'fr' => '/{_locale}/politique-de-confidentialite',
            'en' => '/{_locale}/privacy-policy'
        ],
        name: 'app_legal',
        options: [
            'sitemap' =>
                [
                    'priority' => 0.1,
                    'changefreq' => UrlConcrete::CHANGEFREQ_MONTHLY,
                    'lastmod' => new \DateTime('2025-10-30')
                ]
        ]
    )]
    public function privacyPolicy(): Response
    {
        return $this->render('public/legal/privacy_policy.html.twig');
    }
}
