<?php

namespace App\PropertyEstimation\Controller;

use App\PropertyEstimation\Entity\PropertyEstimation;
use App\PropertyEstimation\Form\PropertyEstimationType;
use App\PropertyEstimation\Message\SendEstimationEmailMessage;
use Doctrine\ORM\EntityManagerInterface;
use Presta\SitemapBundle\Sitemap\Url\UrlConcrete;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;

final class PropertyEstimationController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $bus,
        private readonly TranslatorInterface $translator,
        private readonly RateLimiterFactoryInterface $formEstimationLimiter,
    ) {
    }

    #[Route(
        path: [
            'fr' => '/{_locale}/services/gestion-locative-paris',
            'en' => '/{_locale}/services/property-management-paris',
        ],
        name: 'app_service_landlords',
        options: [
            'sitemap' => [
                'priority' => 0.8,
                'changefreq' => UrlConcrete::CHANGEFREQ_WEEKLY,
                'lastmod' => new \DateTime('2026-01-04'),
            ],
        ],
    )]
    public function index(Request $request): Response
    {
        $form = $this->createForm(PropertyEstimationType::class, new PropertyEstimation());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$this->formEstimationLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
                $form->addError(new \Symfony\Component\Form\FormError(
                    $this->translator->trans('propertyEstimation.form.error.tooManyRequests'),
                ));

                $response = $this->render('public/property_estimation/index.html.twig', ['form' => $form]);
                $response->setStatusCode(Response::HTTP_TOO_MANY_REQUESTS);

                return $response;
            }

            /** @var PropertyEstimation $data */
            $data = $form->getData();
            $now = new \DateTimeImmutable();
            $data->setCreatedAt($now);
            $data->setIp($request->getClientIp());
            $data->setLang($request->getLocale());

            $this->entityManager->persist($data);
            $this->entityManager->flush();

            $this->bus->dispatch(new SendEstimationEmailMessage(
                address: $data->getAddress(),
                propertyCondition: $data->getPropertyCondition(),
                surface: $data->getSurface(),
                bathroom: $data->getBathroom(),
                bedroom: $data->getBedroom(),
                email: $data->getEmail(),
                phoneNumber: $data->getPhoneNumber(),
                lang: $data->getLang() ?? $request->getLocale(),
                ip: $data->getIp(),
                createdAt: $now,
            ));

            if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
                $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

                return $this->render('public/property_estimation/success.stream.html.twig', [
                    'success' => $data,
                ]);
            }

            $this->addFlash('success', $this->translator->trans('propertyEstimation.form.success.title'));

            return $this->redirectToRoute('app_service_landlords', [], Response::HTTP_SEE_OTHER);
        }

        // For Turbo-driven invalid submissions, return a Turbo Stream that
        // replaces the #estimation-form region in place. We use status 200
        // because shared o2switch infrastructure intercepts 4xx responses
        // with its own error page, which would prevent Turbo from rendering
        // our error markup. Stream actions are processed regardless of status.
        if ($form->isSubmitted() && !$form->isValid()
            && TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

            return $this->render('public/property_estimation/form.stream.html.twig', [
                'form' => $form,
            ]);
        }

        return $this->render('public/property_estimation/index.html.twig', [
            'form' => $form,
        ]);
    }
}
