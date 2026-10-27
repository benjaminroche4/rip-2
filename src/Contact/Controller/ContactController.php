<?php

namespace App\Contact\Controller;

use App\Contact\Entity\Contact;
use App\Contact\Form\ContactType;
use App\Contact\Message\SendContactEmailsMessage;
use App\Shared\Webhook\DashboardContactPayload;
use App\Shared\Webhook\MakeWebhookTarget;
use App\Shared\Webhook\NotifyDashboardMessage;
use App\Shared\Webhook\NotifyMakeWebhookMessage;
use Doctrine\ORM\EntityManagerInterface;
use Presta\SitemapBundle\Sitemap\Url\UrlConcrete;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;

final class ContactController extends AbstractController
{
    private const FLASH_PHONE = 'contactSuccessPhone';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $bus,
        private readonly TranslatorInterface $translator,
        #[Target('form_contact')]
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

        // The limiter counts every submission, valid or not: honeypot hits
        // and invalid spam must burn tokens too, not only clean requests.
        if ($form->isSubmitted() && !$this->formContactLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            $form->get('email')->addError(new \Symfony\Component\Form\FormError(
                $this->translator->trans('contact.contactForm.error.tooManyRequests'),
            ));

            $response = $this->render('public/contact/index.html.twig', [
                'contactForm' => $form->createView(),
                'success' => false,
                'successPhoneNumber' => null,
            ]);
            $response->setStatusCode(Response::HTTP_TOO_MANY_REQUESTS);

            return $response;
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $now = new \DateTimeImmutable();
            $contact->setCreatedAt($now);
            $contact->setLang($request->getLocale());
            $contact->setIp($request->getClientIp());

            // Unmapped field: only meaningful for the housing-search help type.
            $offer = \is_string($form->get('offer')->getData()) ? $form->get('offer')->getData() : null;
            $contact->setOffer($offer);

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
                lang: $contact->getLang(),
                ip: $contact->getIp(),
                createdAt: $now,
                offer: $offer,
            ));

            $this->bus->dispatch(new NotifyMakeWebhookMessage([
                'firstName' => $contact->getFirstName(),
                'lastName' => $contact->getLastName(),
                'email' => $contact->getEmail(),
                'phone' => $contact->getPhoneNumber(),
                'helpType' => $this->translator->trans($contact->getHelpType(), locale: $contact->getLang()),
                'offer' => null !== $offer
                    ? $this->translator->trans('contact.contactForm.offer.'.$offer.'.title', locale: $contact->getLang())
                    : null,
                'message' => $contact->getMessage(),
                'company' => $contact->getCompany(),
                'lang' => $contact->getLang(),
                'createdAt' => $now->format(\DateTimeInterface::ATOM),
            ], MakeWebhookTarget::CONTACT));

            // Same request pushed to the Dashboard backoffice as a lead.
            $this->bus->dispatch(new NotifyDashboardMessage(DashboardContactPayload::fromContact($contact)));

            // PRG on purpose (also for Turbo, which follows the 303 as a visit):
            // the landing URL carries ?success=1 so analytics can count the
            // conversion on a real page view. The flash only carries the phone
            // number for the confirmation copy, never the confirmation itself.
            $this->addFlash(self::FLASH_PHONE, $contact->getPhoneNumber());

            return $this->redirectToRoute('app_contact', ['success' => 1], Response::HTTP_SEE_OTHER);
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

        // ?success=1 is only meant to be reached through the redirect above.
        // Anyone reloading or typing it still sees the confirmation (accepted
        // tracking noise), but the response must never be cached or indexed:
        // the shared host's LiteSpeed cache ignores Cache-Control: private.
        $success = !$form->isSubmitted() && $request->query->getBoolean('success');
        $phoneNumber = null;
        if ($success) {
            $session = $request->hasPreviousSession() ? $request->getSession() : null;
            if ($session instanceof FlashBagAwareSessionInterface) {
                $phones = $session->getFlashBag()->get(self::FLASH_PHONE);
                $phoneNumber = \is_string($phones[0] ?? null) ? $phones[0] : null;
            }
        }

        $response = $this->render('public/contact/index.html.twig', [
            'contactForm' => $form->createView(),
            'success' => $success,
            'successPhoneNumber' => $phoneNumber,
        ]);

        if ($success) {
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('X-LiteSpeed-Cache-Control', 'no-cache');
        }

        return $response;
    }
}
