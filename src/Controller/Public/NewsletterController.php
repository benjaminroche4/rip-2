<?php

namespace App\Controller\Public;

use App\Entity\Newsletter;
use App\Form\NewsletterType;
use Doctrine\ORM\EntityManagerInterface;
use Presta\SitemapBundle\Sitemap\Url\UrlConcrete;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final class NewsletterController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
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
    public function index(Request $request): Response
    {
        $resend = \Resend::client($_ENV['RESEND_API_KEY']);

        $newsletter = new Newsletter();
        $form = $this->createForm(NewsletterType::class, $newsletter);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $newsletter->setCreatedAt(new \DateTimeImmutable());
            $newsletter->setSubscribe(1);

            $this->entityManager->persist($newsletter);
            $this->entityManager->flush();

            $resend->contacts->create([
                'email' => $newsletter->getEmail(),
                'segments' => [
                    ['id' => '52a39bfb-e0fe-4aa6-8838-4555bc24f108'],
                ],
            ]);

            $this->addFlash('newsletterSuccess', $this->translator->trans('newsletter.form.success.title'));
            return $this->redirectToRoute('app_newsletter');
        }

        // Si le formulaire a été soumis mais invalide, retourner un status 422 pour Turbo
        $response = $this->render('public/newsletter/index.html.twig', [
            'newsletterForm' => $form->createView(),
        ]);

        if ($form->isSubmitted() && !$form->isValid()) {
            $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $response;
    }
}
