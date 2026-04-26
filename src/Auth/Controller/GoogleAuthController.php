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
