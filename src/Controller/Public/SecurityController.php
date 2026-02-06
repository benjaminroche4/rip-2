<?php

namespace App\Controller\Public;

use Presta\SitemapBundle\Sitemap\Url\UrlConcrete;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    #[Route(
        path: [
            'fr' => '/{_locale}/connexion',
            'en' => '/{_locale}/login',
        ],
        name: 'app_login',
        options: [
            'sitemap' =>
                [
                    'priority' => 0.2,
                    'changefreq' => UrlConcrete::CHANGEFREQ_MONTHLY,
                    'lastmod' => new \DateTime('2026-03-06')
                ]
        ]
    )]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        // get the login error if there is one
        $error = $authenticationUtils->getLastAuthenticationError();

        // last username entered by the user
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('public/security/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
        ]);
    }

    #[Route(
        path: [
            'fr' => '/{_locale}/deconnexion',
            'en' => '/{_locale}/logout',
        ],
        name: 'app_logout'
    )]
    public function logout(): void
    {
        throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }
}
