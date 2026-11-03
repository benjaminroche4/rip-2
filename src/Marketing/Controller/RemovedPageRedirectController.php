<?php

declare(strict_types=1);

namespace App\Marketing\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pages removed from the site (November 2026): "Trouver un locataire" and
 * "Proposer un bien". Their URLs may be indexed or bookmarked, so they answer
 * a permanent redirect to the property management page (the landlord offer)
 * instead of a 404, keeping the acquired ranking. Never listed in the sitemaps.
 */
final class RemovedPageRedirectController extends AbstractController
{
    #[Route(
        path: [
            'fr' => '/{_locale}/services/trouver-un-locataire',
            'en' => '/{_locale}/services/find-a-tenant',
        ],
        name: 'legacy_find_tenant',
        methods: ['GET', 'HEAD'],
    )]
    #[Route(
        path: [
            'fr' => '/{_locale}/proposer-un-bien',
            'en' => '/{_locale}/list-your-property',
        ],
        name: 'legacy_list_property',
        methods: ['GET', 'HEAD'],
    )]
    public function __invoke(): RedirectResponse
    {
        return $this->redirectToRoute('app_service_landlords', [], Response::HTTP_MOVED_PERMANENTLY);
    }
}
