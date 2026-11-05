<?php

namespace App\CaseStudy\Map;

use App\CaseStudy\Domain\CaseStudy;
use App\Shared\Map\BrandPin;
use Symfony\UX\Map\Bridge\Google\GoogleOptions;
use Symfony\UX\Map\Bridge\Google\Option\GestureHandling;
use Symfony\UX\Map\Circle;
use Symfony\UX\Map\Map;
use Symfony\UX\Map\Marker;
use Symfony\UX\Map\Point;

/**
 * Google map of the area a client settled in: a circle around the centre of the district with a
 * round badge in its middle, never a pin on the client's address. The circle is styled by the
 * map-area Stimulus controller.
 */
final class AreaMapFactory
{
    /** District level: the streets around are readable, the exact building is not. */
    private const ZOOM = 14;

    /** Radius of the area, in metres: about the size of a neighbourhood. */
    private const RADIUS = 600;

    public function create(CaseStudy $caseStudy): Map
    {
        $center = new Point($caseStudy->latitude, $caseStudy->longitude);

        return (new Map('default'))
            ->center($center)
            ->zoom(self::ZOOM)
            ->addCircle(new Circle(center: $center, radius: self::RADIUS))
            // Round badge on the centre of the area (not a pin): it names the zone, not an address
            ->addMarker(new Marker(position: $center, icon: BrandPin::areaIcon()))
            ->options(new GoogleOptions(
                // Scrolling the page must not zoom the map: two fingers / ctrl + wheel do
                gestureHandling: GestureHandling::COOPERATIVE,
                mapTypeControl: false,
                streetViewControl: false,
                fullscreenControl: false,
            ));
    }
}
