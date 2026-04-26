<?php

namespace App\Controller\Public;

use App\Entity\Contact;
use App\Form\ContactType;
use App\Message\NotifyMakeWebhookMessage;
use App\Message\SendContactEmailsMessage;
use Doctrine\ORM\EntityManagerInterface;
use Presta\SitemapBundle\Sitemap\Url\UrlConcrete;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;

final class ContactController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $bus,
        private readonly TranslatorInterface $translator,
    ) {}

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

        return $this->render('public/contact/index.html.twig', [
            'contactForm' => $form->createView(),
        ]);
    }
}
