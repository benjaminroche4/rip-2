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

final class PropertyListingController extends AbstractController
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly RateLimiterFactoryInterface $formListingLimiter,
        private readonly ListingPhotoStorage $photoStorage,
        private readonly MessageBusInterface $bus,
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
            $this->addFlash('listingSuccess', $this->successRecap($form->getData(), 0));

            return $this->redirectToRoute('app_list_property', [], Response::HTTP_SEE_OTHER);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$this->formListingLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
                $form->addError(new FormError(
                    $this->translator->trans('listProperty.form.error.tooManyRequests'),
                ));

                $response = $this->render('public/property_listing/index.html.twig', ['form' => $form]);
                $response->setStatusCode(Response::HTTP_TOO_MANY_REQUESTS);

                return $response;
            }

            /** @var PropertyListingSubmission $data */
            $data = $form->getData();

            $now = new \DateTimeImmutable();
            $photos = $form->get('photos')->getData() ?? [];
            if ([] !== $photos) {
                $this->photoStorage->store((string) $data->address, (string) $data->fullName, $photos, $now);
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
                photosFolder: [] !== $photos ? $this->photoStorage->folderName((string) $data->address, (string) $data->fullName, $now) : null,
                lang: $request->getLocale(),
                ip: $request->getClientIp(),
                createdAt: $now,
            ));

            // The submission recap travels in the flash so the confirmation
            // view can replay it (photo fan + summary card).
            $this->addFlash('listingSuccess', $this->successRecap($data, \count($photos)));

            return $this->redirectToRoute('app_list_property', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('public/property_listing/index.html.twig', [
            'form' => $form,
        ]);
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
