<?php

namespace App\Auth\Controller;

use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class GoogleAuthController extends AbstractController
{
    #[Route('/connect/google', name: 'app_google_login')]
    public function connect(ClientRegistry $clientRegistry): Response
    {
        // Already-authenticated users have no business going through the OAuth
        // dance again — funnel them back home rather than triggering a new
        // Google redirect that would silently log them out and back in.
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        return $clientRegistry
            ->getClient('google')
            ->redirect(['openid', 'email', 'profile']);
    }

    #[Route('/connect/google/check', name: 'app_google_login_check')]
    public function check(): Response
    {
        throw new \LogicException('This method can be blank - it will be intercepted by the GoogleAuthenticator.');
    }
}
