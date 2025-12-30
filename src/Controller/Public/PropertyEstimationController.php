<?php

namespace App\Controller\Public;

use App\Entity\PropertyEstimation;
use App\Enum\EmailAddress;
use App\Form\PropertyEstimationType;
use Doctrine\ORM\EntityManagerInterface;
use Presta\SitemapBundle\Sitemap\Url\UrlConcrete;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;

final class PropertyEstimationController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
    )
    {
    }

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
            $data = $form->getData();
            $data->setCreatedAt(new \DateTimeImmutable());
            $data->setIp($request->getClientIp());
            $data->setLang($request->getLocale());

            $this->entityManager->persist($data);
            $this->entityManager->flush();

            $estimationEmail = (
            (new TemplatedEmail())
                ->from('Contact <contact@relocation-in-paris.fr>')
                ->to(EmailAddress::CONTACT->value)
                ->subject('🏠 Demande d\'éstimation | Relocation In Paris')
                ->htmlTemplate('emails/property_estimation.html.twig')
                ->context([
                    'address' => $data->getAddress(),
                    'propertyCondition' => $data->getPropertyCondition(),
                    'surface' => $data->getSurface(),
                    'bathroom' => $data->getBathroom(),
                    'bedroom' => $data->getBedroom(),
                    'emailLead' => $data->getEmail(),
                    'phoneNumber' => $data->getPhoneNumber(),

                    'createdAt' => new \DateTimeImmutable(),
                    'lang' => $data->getLang(),
                    'ip' => $data->getIp(),
                ])
            );

            try {
                $this->mailer->send($estimationEmail);
            } catch (TransportExceptionInterface $e) {
                $this->logger->error('An error occurred while sending :'. $e->getMessage());
            }

            if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
                $request->setRequestFormat(TurboBundle::STREAM_FORMAT);
                return $this->render('public/property_estimation/success.stream.html.twig', ['success' => $data]);
            }

            return $this->redirectToRoute('success', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('public/property_estimation/index.html.twig', [
            'form' => $form,
        ]);
    }
}
