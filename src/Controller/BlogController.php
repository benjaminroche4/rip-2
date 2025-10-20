<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class BlogController extends AbstractController
{
    #[Route('/blog', name: 'app_blog')]
    public function blogList(): Response
    {
        return $this->render('public/blog/index.html.twig', [
            'controller_name' => 'BlogController',
        ]);
    }
}
