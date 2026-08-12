<?php

namespace App\PropertyListing\Controller;

use App\PropertyListing\Domain\PropertyListingSubmission;
use App\PropertyListing\Form\PropertyListingType;
use App\PropertyListing\Message\SendListingEmailMessage;
use App\PropertyListing\Service\ListingPhotoStorage;
use Presta\SitemapBundle\Sitemap\Url\UrlConcrete;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;

final class PropertyListingController extends AbstractController
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly RateLimiterFactoryInterface $formListingLimiter,
        private readonly ListingPhotoStorage $photoStorage,
        private readonly MessageBusInterface $bus,
        private readonly \Psr\Log\LoggerInterface $logger,
    ) {
    }

    #[Route(
        path: [
            'fr' => '/{_locale}/proposer-un-bien',
            'en' => '/{_locale}/list-your-property',
        ],
        name: 'app_list_property',
        options: [
            'sitemap' => [
                'priority' => 0.7,
                'changefreq' => UrlConcrete::CHANGEFREQ_WEEKLY,
                'lastmod' => new \DateTime('2026-07-17'),
            ],
        ],
    )]
    public function index(Request $request): Response
    {
        $form = $this->createForm(PropertyListingType::class, new PropertyListingSubmission());
        $form->handleRequest($request);

        // Honeypot filled: answer exactly like a success so bots cannot tell
        // the trap apart, but store and send nothing.
        if ($form->isSubmitted() && '' !== (string) $form->get('website')->getData()) {
            return $this->success($request, $this->successRecap($form->getData(), 0));
        }

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$this->formListingLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
                $form->addError(new FormError(
                    $this->translator->trans('listProperty.form.error.tooManyRequests'),
                ));

                // Turbo submissions get the error as a stream with status 200:
                // shared o2switch infrastructure intercepts 4xx responses with
                // its own error page, which would prevent Turbo from rendering
                // our markup. Stream actions are processed regardless of status.
                if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
                    $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

                    return $this->render('public/property_listing/form.stream.html.twig', [
                        'form' => $form->createView(),
                    ]);
                }

                $response = $this->render('public/property_listing/index.html.twig', ['form' => $form]);
                $response->setStatusCode(Response::HTTP_TOO_MANY_REQUESTS);

                return $response;
            }

            /** @var PropertyListingSubmission $data */
            $data = $form->getData();

            $now = new \DateTimeImmutable();
            $photos = $form->get('photos')->getData() ?? [];
            $photosFolder = [] !== $photos
                ? $this->photoStorage->folderName((string) $data->address, (string) $data->fullName, $now)
                : null;
            if (null !== $photosFolder) {
                try {
                    $this->photoStorage->store($photosFolder, $photos);
                } catch (\Throwable $e) {
                    // Photos are accessory: a storage outage must not lose
                    // the whole submission. The email still goes out.
                    $this->logger->error('Listing photo storage failed: '.$e->getMessage());
                    $photosFolder = null;
                }
            }

            $this->bus->dispatch(new SendListingEmailMessage(
                fullName: (string) $data->fullName,
                email: (string) $data->email,
                phoneNumber: (string) $data->phoneNumber,
                address: (string) $data->address,
                propertyType: 'listProperty.form.propertyType.choice.'.$data->propertyType->value,
                propertyStatus: 'listProperty.form.propertyStatus.choice.'.$data->propertyStatus->value,
                bedrooms: (int) $data->bedrooms,
                bathrooms: (int) $data->bathrooms,
                surface: (int) $data->surface,
                floor: $data->floor,
                buildingFloors: $data->buildingFloors,
                furnishing: 'listProperty.form.furnishing.choice.'.$data->furnishing->value,
                orientations: array_map(
                    static fn ($orientation) => 'listProperty.form.orientations.choice.'.$orientation->value,
                    $data->orientations,
                ),
                leaseTypes: array_map(
                    static fn ($leaseType) => 'listProperty.form.leaseType.choice.'.$leaseType->value,
                    $data->leaseTypes,
                ),
                rent: $data->rent,
                charges: $data->charges,
                deposit: $data->deposit,
                amenities: array_map(
                    static fn ($amenity) => 'listProperty.form.amenities.choice.'.$amenity->value,
                    $data->amenities,
                ),
                note: null !== $data->note && '' !== trim($data->note) ? trim($data->note) : null,
                photosCount: \count($photos),
                photosFolder: $photosFolder,
                lang: $request->getLocale(),
                ip: $request->getClientIp(),
                createdAt: $now,
            ));

            return $this->success($request, $this->successRecap($data, \count($photos)));
        }

        // Invalid Turbo submission: replace the #listing-form region in place
        // (status 200, see the rate-limit branch for why not 422).
        if ($form->isSubmitted() && !$form->isValid()
            && TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

            return $this->render('public/property_listing/form.stream.html.twig', [
                'form' => $form->createView(),
            ]);
        }

        // Non-Turbo fallback only: the confirmation GET after the PRG redirect
        // must never be cached, or the shared host's LiteSpeed cache keeps
        // serving the confirmation on refresh instead of the fresh form.
        $showsConfirmation = $request->hasPreviousSession()
            && $request->getSession()->getFlashBag()->has('listingSuccess');

        $response = $this->render('public/property_listing/index.html.twig', [
            'form' => $form,
        ]);

        if ($showsConfirmation) {
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('X-LiteSpeed-Cache-Control', 'no-cache');
        }

        return $response;
    }

    /**
     * Turbo submissions get the confirmation as a stream on the POST response
     * itself: nothing confirmation-related ever travels on a cacheable GET.
     * The no-JS fallback keeps the classic flash + redirect (PRG).
     *
     * @param array<string, mixed> $recap
     */
    private function success(Request $request, array $recap): Response
    {
        if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

            return $this->render('public/property_listing/success.stream.html.twig', [
                'success' => $recap,
            ]);
        }

        $this->addFlash('listingSuccess', $recap);

        return $this->redirectToRoute('app_list_property', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * Minimal snapshot for the confirmation view (the full recap travels by
     * email): photo fan, map card and "sent to" mention.
     *
     * @return array<string, mixed>
     */
    private function successRecap(PropertyListingSubmission $data, int $photosCount): array
    {
        return [
            'photosCount' => $photosCount,
            'address' => (string) $data->address,
            'placeId' => (string) $data->placeId,
            'email' => (string) $data->email,
        ];
    }
}
