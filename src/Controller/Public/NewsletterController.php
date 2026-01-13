<?php

namespace App\Controller\Public;

use App\Entity\Newsletter;
use App\Form\NewsletterType;
use Doctrine\ORM\EntityManagerInterface;
use Presta\SitemapBundle\Sitemap\Url\UrlConcrete;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
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
    public function index(Request $request): Response
    {
        $resend = \Resend::client('re_erqYXDWJ_CYNima1DVyELRkRGGVfsudwr');

        $newsletter = new Newsletter();
        $form = $this->createForm(NewsletterType::class, $newsletter);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $newsletter->setCreatedAt(new \DateTimeImmutable());
            $newsletter->setSubscribe(1);

            $this->entityManager->persist($newsletter);
            $this->entityManager->flush();

            $resend->contacts->segments->add(
                contact: 'test@gmao.com',
                segmentId: '52a39bfb-e0fe-4aa6-8838-4555bc24f108'
            );

            $this->addFlash('newsletterSuccess', $this->translator->trans('newsletter.form.success.title'));
            return $this->redirectToRoute('app_newsletter');
        }

        return $this->render('public/newsletter/index.html.twig', [
            'newsletterForm' => $form->createView(),
        ]);
    }
}
