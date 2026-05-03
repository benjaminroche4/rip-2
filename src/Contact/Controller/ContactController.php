<?php

namespace App\Contact\Controller;

use App\Contact\Entity\Contact;
use App\Contact\Form\ContactType;
use App\Contact\Message\SendContactEmailsMessage;
use App\Shared\Webhook\NotifyMakeWebhookMessage;
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

final class ContactController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $bus,
        private readonly TranslatorInterface $translator,
        private readonly RateLimiterFactoryInterface $formContactLimiter,
    ) {
    }

    #[Route(
        path: [
            'fr' => '/{_locale}/contact',
            'en' => '/{_locale}/contact',
        ],
        name: 'app_contact',
        options: [
            'sitemap' => [
                'priority' => 0.8,
                'changefreq' => UrlConcrete::CHANGEFREQ_MONTHLY,
                'lastmod' => new \DateTime('2025-10-09'),
            ],
        ],
    )]
    public function index(Request $request): Response
    {
        $contact = new Contact();
        $form = $this->createForm(ContactType::class, $contact);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$this->formContactLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
                $form->get('email')->addError(new \Symfony\Component\Form\FormError(
                    $this->translator->trans('contact.contactForm.error.tooManyRequests'),
                ));

                $response = $this->render('public/contact/index.html.twig', ['contactForm' => $form->createView()]);
                $response->setStatusCode(Response::HTTP_TOO_MANY_REQUESTS);

                return $response;
            }

            $now = new \DateTimeImmutable();
            $contact->setCreatedAt($now);
            $contact->setLang($request->getLocale());
            $contact->setIp($request->getClientIp());

            $this->entityManager->persist($contact);
            $this->entityManager->flush();

            $this->bus->dispatch(new SendContactEmailsMessage(
                firstName: $contact->getFirstName(),
                lastName: $contact->getLastName(),
                email: $contact->getEmail(),
                phoneNumber: $contact->getPhoneNumber(),
                helpType: $contact->getHelpType(),
                message: $contact->getMessage(),
                company: $contact->getCompany(),
                lang: $contact->getLang() ?? $request->getLocale(),
                ip: $contact->getIp(),
                createdAt: $now,
            ));

            $this->bus->dispatch(new NotifyMakeWebhookMessage([
                'firstName' => $contact->getFirstName(),
                'lastName' => $contact->getLastName(),
                'email' => $contact->getEmail(),
                'phone' => $contact->getPhoneNumber(),
                'helpType' => $contact->getHelpType(),
                'message' => $contact->getMessage(),
                'company' => $contact->getCompany(),
                'lang' => $contact->getLang(),
                'createdAt' => $now->format(\DateTimeInterface::ATOM),
            ]));

            if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
                $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

                return $this->render('public/contact/success.stream.html.twig', [
                    'success' => $contact,
                ]);
            }

            $this->addFlash('contactSuccess', $this->translator->trans('contact.contactForm.success.title'));

            return $this->redirectToRoute('app_contact');
        }

        // For Turbo-driven invalid submissions, return a Turbo Stream that
        // replaces the #contact-form region in place. We use status 200
        // because shared o2switch infrastructure intercepts 4xx responses
        // with its own error page, which would prevent Turbo from rendering
        // our error markup. Stream actions are processed regardless of status.
        if ($form->isSubmitted() && !$form->isValid()
            && TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

            return $this->render('public/contact/form.stream.html.twig', [
                'contactForm' => $form->createView(),
            ]);
        }

        return $this->render('public/contact/index.html.twig', [
            'contactForm' => $form->createView(),
        ]);
    }
}
