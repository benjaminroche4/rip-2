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
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;

final class ContactController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
        private readonly HttpClientInterface $http,
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
    public function index(Request $request): Response
    {
        $contact = new Contact();
        $form = $this->createForm(ContactType::class, $contact);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $contact->setCreatedAt(new \DateTimeImmutable());
            $contact->setLang($request->getLocale());
            $contact->setIp($request->getClientIp());

            $this->entityManager->persist($contact);
            $this->entityManager->flush();

            $adminEmail = (new TemplatedEmail())
                ->from('Contact <contact@relocation-in-paris.fr>')
                ->to(EmailAddress::CONTACT->value)
                ->subject('📩 Demande de contact | Relocation In Paris')
                ->htmlTemplate('emails/contact_admin.html.twig')
                ->context([
                    'fistName' => $contact->getFirstName(),
                    'lastName' => $contact->getLastName(),
                    'emailContact' => $contact->getEmail(),
                    'phoneNumber' => $contact->getPhoneNumber(),
                    'helpType' => $contact->getHelpType(),
                    'message' => $contact->getMessage(),
                    'company' => $contact->getCompany(),
                    'createdAt' => new \DateTimeImmutable(),
                    'lang' => $contact->getLang(),
                    'ip' => $contact->getIp(),
                ]);

            $clientEmail = (new TemplatedEmail())
                ->from('Contact <contact@relocation-in-paris.fr>')
                ->to($contact->getEmail())
                ->subject($contact->getLang() === 'fr'
                    ? sprintf('%s, votre conseiller vous contacte sous 30 minutes', $contact->getFirstName())
                    : sprintf('%s, your advisor will contact you within 30 minutes', $contact->getFirstName())
                )
                ->htmlTemplate('emails/contact_client.html.twig')
                ->context([
                    'fistName' => $contact->getFirstName(),
                    'lastName' => $contact->getLastName(),
                    'emailContact' => $contact->getEmail(),
                    'phoneNumber' => $contact->getPhoneNumber(),
                    'helpType' => $contact->getHelpType(),
                    'message' => $contact->getMessage(),
                    'company' => $contact->getCompany(),
                    'createdAt' => new \DateTimeImmutable(),
                    'lang' => $contact->getLang(),
                ]);

            try {
                $this->mailer->send($adminEmail);
                $this->mailer->send($clientEmail);
            } catch (TransportExceptionInterface $e) {
                $this->logger->error('An error occurred while sending :'. $e->getMessage());
            }

            // Send contact data to Make webhook
            try {
                $this->http->request('POST', $_ENV['MAKE_WEBHOOK_URL'], [
                    'json' => [
                        'firstName'  => $contact->getFirstName(),
                        'lastName'   => $contact->getLastName(),
                        'email'      => $contact->getEmail(),
                        'phone'      => $contact->getPhoneNumber(),
                        'helpType'   => $contact->getHelpType(),
                        'message'    => $contact->getMessage(),
                        'company'    => $contact->getCompany(),
                        'lang'       => $contact->getLang(),
                        'createdAt'  => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                    ],
                    'timeout' => 3,
                ]);
            } catch (\Exception $e) {
                $this->logger->warning('Make webhook failed: ' . $e->getMessage());
            }

            if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
                $request->setRequestFormat(TurboBundle::STREAM_FORMAT);
                return $this->render('public/contact/success.stream.html.twig', [
                    'success' => $contact
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
