<?php

namespace App\Controller\Public;

use App\Entity\Contact;
use App\Enum\EmailAddress;
use App\Form\ContactType;
use Doctrine\ORM\EntityManagerInterface;
use Presta\SitemapBundle\Sitemap\Url\UrlConcrete;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Bridge\Resend\Transport\ResendApiTransport;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ContactController extends AbstractController
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
            'fr' => '/{_locale}/contact',
            'en' => '/{_locale}/contact',
        ],
        name: 'app_contact',
        options: [
            'sitemap' =>
                [
                    'priority' => 0.8,
                    'changefreq' => UrlConcrete::CHANGEFREQ_MONTHLY,
                    'lastmod' => new \DateTime('2025-10-09')
                ]
        ]
    )]
    public function index(Request $request, Resend $transport): Response
    {
        //appel resend ici pour test
        $resend = Resend

        $contact = new Contact();
        $form = $this->createForm(ContactType::class, $contact);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $contact->setCreatedAt(new \DateTimeImmutable());
            $contact->setLang($request->getLocale());
            $contact->setIp($request->getClientIp());

            $this->entityManager->persist($contact);
            $this->entityManager->flush();

            $contactEmail = (
            (new TemplatedEmail())
                ->from('Contact <contact@relocation-in-paris.fr>')
                ->to(EmailAddress::CONTACT->value)
                ->subject('📩 Demande de contact | Relocation In Paris')
                ->htmlTemplate('emails/contact.html.twig')
                ->context([
                    'fistName' => $contact->getFirstName(),
                    'lastName' => $contact->getLastName(),
                    'emailContact' => $contact->getEmail(),
                    'phoneNumber' => $contact->getPhoneNumber(),
                    'helpType' => $contact->getHelpType(),
                    'message' => $contact->getMessage(),

                    'createdAt' => new \DateTimeImmutable(),
                    'lang' => $contact->getLang(),
                    'ip' => $contact->getIp(),
                ])
            );

            try {
                $this->mailer->send($contactEmail);
            } catch (TransportExceptionInterface $e) {
                $this->logger->error('An error occurred while sending :'. $e->getMessage());
            }

            $this->addFlash('newsletterSuccess', $this->translator->trans('newsletter.form.success.title'));
            return $this->redirectToRoute('app_contact');
        }
        return $this->render('public/contact/index.html.twig', [
            'contactForm' => $form->createView(),
        ]);
    }
}
