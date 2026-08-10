<?php

declare(strict_types=1);

namespace App\Visit\Twig\Components;

use App\Visit\Domain\GeoPoint;
use App\Visit\Domain\VisitSummary;
use App\Visit\Repository\VisitRepository;
use App\Visit\Service\WalkingRoutePlanner;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\Map\Bridge\Google\GoogleOptions;
use Symfony\UX\Map\Map;
use Symfony\UX\Map\Point;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Upcoming visits split into reading sections (today / tomorrow / later),
 * plus the day map data: today's geocoded visits in chronological order,
 * numbered like their pins, with the walking route drawn client-side by the
 * visit-map Stimulus controller.
 */
#[AsTwigComponent(name: 'Visit:VisitList', template: 'components/Visit/VisitList.html.twig')]
final class VisitList
{
    private const TIMEZONE = 'Europe/Paris';

    /** Secret admin URL prefix, needed to build links inside the component. */
    public string $adminPrefix = '';

    /** @var list<VisitSummary>|null */
    private ?array $summariesCache = null;

    public function __construct(
        private readonly VisitRepository $repository,
        private readonly Security $security,
        private readonly ClockInterface $clock,
        private readonly WalkingRoutePlanner $routePlanner,
    ) {
    }

    public function mount(): void
    {
        if (!$this->security->isGranted('ROLE_SECTION_VISITS')) {
            throw new AccessDeniedException('Admin access required.');
        }
    }

    /**
     * @return list<VisitSummary>
     */
    public function getTodayVisits(): array
    {
        return $this->onDay($this->today());
    }

    /**
     * @return list<VisitSummary>
     */
    public function getTomorrowVisits(): array
    {
        return $this->onDay($this->today()->modify('+1 day'));
    }

    /**
     * Everything after tomorrow, still chronological.
     *
     * @return list<VisitSummary>
     */
    public function getLaterVisits(): array
    {
        $afterTomorrow = $this->today()->modify('+2 days')->format('Y-m-d');

        return array_values(array_filter(
            $this->summaries(),
            static fn (VisitSummary $visit): bool => $visit->scheduledAt->format('Y-m-d') >= $afterTomorrow,
        ));
    }

    public function getTotalCount(): int
    {
        return \count($this->summaries());
    }

    /**
     * Today's visits that made it through geocoding — the ones on the map,
     * numbered by chronological position among ALL today's visits so list
     * badges and pins always match.
     *
     * @return list<array{position: int, visit: VisitSummary}>
     */
    public function getMappableTodayVisits(): array
    {
        $mappable = [];
        foreach ($this->getTodayVisits() as $index => $visit) {
            if ($visit->hasCoordinates()) {
                $mappable[] = ['position' => $index + 1, 'visit' => $visit];
            }
        }

        return $mappable;
    }

    /**
     * Bare map shell centered on Paris — pins and the walking route are drawn
     * by the visit-map controller from getMapPayload(), so the numbered
     * markers and the DirectionsService route live in one place.
     */
    public function getMap(): Map
    {
        return (new Map('default'))
            ->center(new Point(48.8566, 2.3522))
            ->zoom(12)
            ->options(new GoogleOptions(
                mapTypeControl: false,
                streetViewControl: false,
                fullscreenControl: false,
            ));
    }

    /**
     * JSON payload for the visit-map controller: today's geocoded visits in
     * pin order.
     */
    public function getMapPayload(): string
    {
        $points = [];
        foreach ($this->getMappableTodayVisits() as $entry) {
            $points[] = [
                'lat' => $entry['visit']->latitude,
                'lng' => $entry['visit']->longitude,
                'label' => (string) $entry['position'],
                'title' => $entry['visit']->scheduledAt->format('H:i').' '.$entry['visit']->dossierName,
            ];
        }

        return json_encode($points, \JSON_THROW_ON_ERROR);
    }

    /**
     * Walking path through today's pins, decoded server-side from the Routes
     * API (the client only draws a polyline). Empty when the route is
     * unavailable — pins alone still work.
     */
    public function getRoutePayload(): string
    {
        $stops = [];
        foreach ($this->getMappableTodayVisits() as $entry) {
            $stops[] = new GeoPoint((float) $entry['visit']->latitude, (float) $entry['visit']->longitude);
        }

        return json_encode($this->routePlanner->route($stops) ?? [], \JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<VisitSummary>
     */
    private function onDay(\DateTimeImmutable $day): array
    {
        $key = $day->format('Y-m-d');

        return array_values(array_filter(
            $this->summaries(),
            static fn (VisitSummary $visit): bool => $visit->scheduledAt->format('Y-m-d') === $key,
        ));
    }

    private function today(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone(self::TIMEZONE));
    }

    /**
     * @return list<VisitSummary>
     */
    private function summaries(): array
    {
        return $this->summariesCache ??= $this->repository->findUpcomingSummaries($this->today());
    }
}
