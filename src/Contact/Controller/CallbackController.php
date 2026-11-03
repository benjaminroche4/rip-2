<?php

declare(strict_types=1);

namespace App\Contact\Controller;

use App\Contact\Domain\ContactMethod;
use App\Contact\Domain\ContactSlot;
use App\Contact\Entity\Contact;
use App\Contact\Form\CallbackType;
use App\Contact\Form\ContactType;
use App\Contact\Service\ContactNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Presta\SitemapBundle\Sitemap\Url\UrlConcrete;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;

/**
 * "On vous rappelle" page: a focused call-back form without header nor footer,
 * reached from every "On vous rappelle" call to action. Same pipeline as the
 * contact form (contact row, emails, Make, Dashboard) and the same response
 * model as ContactController.
 */
final class CallbackController extends AbstractController
{
    private const FLASH_PHONE = 'callbackSuccessPhone';
    private const FLASH_SLOT = 'callbackSuccessSlot';
    private const FLASH_METHOD = 'callbackSuccessMethod';
    private const NO_CONFIRMATION = ['phoneNumber' => null, 'contactSlot' => null, 'contactMethod' => null];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ContactNotifier $notifier,
        private readonly TranslatorInterface $translator,
        #[Target('form_contact')]
        private readonly RateLimiterFactoryInterface $formContactLimiter,
    ) {
    }

    #[Route(
        path: [
            'fr' => '/{_locale}/on-vous-rappelle',
            'en' => '/{_locale}/request-a-callback',
        ],
        name: 'app_callback',
        methods: ['GET', 'POST'],
        options: [
            'sitemap' => [
                'priority' => 0.7,
                'changefreq' => UrlConcrete::CHANGEFREQ_MONTHLY,
                'lastmod' => new \DateTime('2026-11-03'),
            ],
        ],
    )]
    public function __invoke(Request $request): Response
    {
        $contact = new Contact();
        $form = $this->createForm(CallbackType::class, $contact);
        $form->handleRequest($request);

        // Every submission burns a token (valid, invalid or honeypot), shared with the contact form
        if ($form->isSubmitted() && !$this->formContactLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            $form->get('email')->addError(new FormError($this->translator->trans('contact.contactForm.error.tooManyRequests')));

            $response = $this->renderPage($form, false, self::NO_CONFIRMATION);
            $response->setStatusCode(Response::HTTP_TOO_MANY_REQUESTS);

            return $response;
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $contact
                ->setSource(Contact::SOURCE_CALLBACK)
                // A call-back is always about finding a home (the packages are the housing-search ones)
                ->setHelpType(ContactType::HOUSING_HELP_TYPE)
                ->setCreatedAt(new \DateTimeImmutable())
                ->setLang($request->getLocale())
                ->setIp($request->getClientIp());

            $this->entityManager->persist($contact);
            $this->entityManager->flush();

            $this->notifier->notify($contact);

            // Same PRG as the contact form: ?success=1 lets analytics count the conversion
            $this->addFlash(self::FLASH_PHONE, $contact->getPhoneNumber());
            if (null !== $contact->getContactSlot()) {
                $this->addFlash(self::FLASH_SLOT, $contact->getContactSlot()->value);
            }
            if (null !== $contact->getContactMethod()) {
                $this->addFlash(self::FLASH_METHOD, $contact->getContactMethod()->value);
            }

            return $this->redirectToRoute('app_callback', ['success' => 1], Response::HTTP_SEE_OTHER);
        }

        // Turbo invalid submission: replace the form in place, 200 because o2switch hijacks 4xx pages
        if ($form->isSubmitted() && TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

            return $this->render('public/contact/callback_form.stream.html.twig', ['callbackForm' => $form->createView()]);
        }

        $success = !$form->isSubmitted() && $request->query->getBoolean('success');
        $confirmation = self::NO_CONFIRMATION;
        if ($success) {
            $session = $request->hasPreviousSession() ? $request->getSession() : null;
            if ($session instanceof FlashBagAwareSessionInterface) {
                $flashes = $session->getFlashBag();
                $first = static fn (array $values): ?string => \is_string($values[0] ?? null) ? $values[0] : null;
                $confirmation = [
                    'phoneNumber' => $first($flashes->get(self::FLASH_PHONE)),
                    // Only known enum values reach the template
                    'contactSlot' => ContactSlot::tryFrom($first($flashes->get(self::FLASH_SLOT)) ?? '')?->value,
                    'contactMethod' => ContactMethod::tryFrom($first($flashes->get(self::FLASH_METHOD)) ?? '')?->value,
                ];
            }
        }

        $response = $this->renderPage($form, $success, $confirmation);
        if ($form->isSubmitted()) {
            // No-JS fallback of an invalid submission
            $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($success) {
            // LiteSpeed (o2switch) ignores Cache-Control: private: never cache the confirmation
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('X-LiteSpeed-Cache-Control', 'no-cache');
        }

        return $response;
    }

    /**
     * @param FormInterface<Contact>                                                    $form
     * @param array{phoneNumber: ?string, contactSlot: ?string, contactMethod: ?string} $confirmation
     */
    private function renderPage(FormInterface $form, bool $success, array $confirmation): Response
    {
        return $this->render('public/contact/callback.html.twig', [
            'callbackForm' => $form->createView(),
            'success' => $success,
            'confirmation' => $confirmation,
        ]);
    }
}
