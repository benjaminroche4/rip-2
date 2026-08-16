<?php

declare(strict_types=1);

namespace App\RealEstateAgent\Twig\Components;

use App\Contact\Domain\ParisDistricts;
use App\RealEstateAgent\Domain\AgencySummary;
use App\RealEstateAgent\Domain\AgentSpecialty;
use App\RealEstateAgent\Domain\AgentSummary;
use App\RealEstateAgent\Repository\AgencyRepository;
use App\RealEstateAgent\Repository\RealEstateAgentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Map\Bridge\Google\GoogleOptions;
use Symfony\UX\Map\Bridge\Google\Option\GestureHandling;
use Symfony\UX\Map\Icon\Icon;
use Symfony\UX\Map\InfoWindow;
use Symfony\UX\Map\Map;
use Symfony\UX\Map\Marker;
use Symfony\UX\Map\Point;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Real-estate agents directory: an alphabetical list with a debounced
 * free-text search, and a toggle to switch between agents and their
 * agencies (each agency showing how many agents belong to it).
 */
#[AsLiveComponent(name: 'RealEstateAgent:AgentList', template: 'components/RealEstateAgent/AgentList.html.twig')]
final class AgentList
{
    use AgentsSectionGuard;
    use DefaultActionTrait;

    private const VIEWS = ['agents', 'agencies'];

    private const TABS = ['all', 'favorites'];

    private const DISPLAYS = ['list', 'map'];

    /** Secret admin URL prefix, needed to build links inside the component. */
    #[LiveProp]
    public string $adminPrefix = '';

    /** Free-text search. Debounced client-side, mirrored in the URL. */
    #[LiveProp(writable: true, url: true, onUpdated: 'onSearchUpdated')]
    public string $search = '';

    /** Active view: 'agents' (default) or 'agencies'. Mirrored in the URL. */
    #[LiveProp(writable: true, url: true)]
    public string $view = 'agents';

    /** Onglet des deux vues : 'all' ou 'favorites', comme la portée dossiers. */
    #[LiveProp(writable: true, url: true)]
    public string $tab = 'all';

    /** Affichage de la vue agences : 'list' (défaut) ou 'map'. Mirroré dans l'URL. */
    #[LiveProp(writable: true, url: true)]
    public string $display = 'list';

    /**
     * Filtre spécialité de la vue agents ('' = toutes). Writable + url : une
     * valeur inconnue arrivée par l'URL est neutralisée à la lecture
     * (getActiveSpecialty), jamais passée telle quelle à la requête.
     */
    #[LiveProp(writable: true, url: true, onUpdated: 'onFiltersUpdated')]
    public string $specialty = '';

    /** Filtre quartier de la vue agents ('' = tous), même neutralisation à la lecture. */
    #[LiveProp(writable: true, url: true, onUpdated: 'onFiltersUpdated')]
    public string $area = '';

    /** Lignes visibles : la page grandit par "Voir plus", jamais tout d'un coup. */
    #[LiveProp]
    public int $limit = self::PAGE;

    private const PAGE = 25;

    public function __construct(
        private readonly RealEstateAgentRepository $repository,
        private readonly AgencyRepository $agencies,
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function mount(): void
    {
        $this->ensureAdmin();
        // Ancienne URL ?view=favorites (ex-entrée du switcher) : retombe sur
        // la vue agents avec l'onglet Favoris.
        if ('favorites' === $this->view) {
            $this->view = 'agents';
            $this->tab = 'favorites';
        }
    }

    #[LiveAction]
    public function chooseView(#[LiveArg] string $view): void
    {
        $this->ensureAdmin();
        if (!\in_array($view, self::VIEWS, true)) {
            throw new NotFoundHttpException('Unknown view.');
        }
        $this->view = $view;
        $this->limit = self::PAGE;
    }

    /** Onglet Tous / Favoris, partagé par les deux vues. */
    #[LiveAction]
    public function chooseTab(#[LiveArg] string $tab): void
    {
        $this->ensureAdmin();
        if (!\in_array($tab, self::TABS, true)) {
            throw new NotFoundHttpException('Unknown tab.');
        }
        $this->tab = $tab;
        $this->limit = self::PAGE;
    }

    /** Affichage Liste / Carte de la vue agences. */
    #[LiveAction]
    public function chooseDisplay(#[LiveArg] string $display): void
    {
        $this->ensureAdmin();
        if (!\in_array($display, self::DISPLAYS, true)) {
            throw new NotFoundHttpException('Unknown display.');
        }
        $this->display = $display;
    }

    /** Favori d'équipe (global) : bascule le cœur depuis la card, la liste se re-rend. */
    #[LiveAction]
    public function toggleFavorite(#[LiveArg] int $id, EntityManagerInterface $em): void
    {
        $this->ensureAdmin();

        $agent = $this->repository->find($id)
            ?? throw new NotFoundHttpException('Unknown agent.');
        $agent->setFavoritedAt($agent->isFavorite() ? null : new \DateTimeImmutable());
        $em->flush();
    }

    /** Favori d'équipe côté agences : même bascule que le cœur des agents. */
    #[LiveAction]
    public function toggleAgencyFavorite(#[LiveArg] int $id, EntityManagerInterface $em): void
    {
        $this->ensureAdmin();

        $agency = $this->agencies->find($id)
            ?? throw new NotFoundHttpException('Unknown agency.');
        $agency->setFavoritedAt($agency->isFavorite() ? null : new \DateTimeImmutable());
        $em->flush();
    }

    /** Chip spécialité : single avec toggle-off (recliquer désélectionne). */
    #[LiveAction]
    public function toggleSpecialty(#[LiveArg] string $specialty): void
    {
        $this->ensureAdmin();
        if (null === AgentSpecialty::tryFrom($specialty)) {
            throw new NotFoundHttpException('Unknown specialty.');
        }
        $this->specialty = $this->specialty === $specialty ? '' : $specialty;
        $this->limit = self::PAGE;
    }

    /** Dropdown quartier : '' efface le filtre, recliquer l'actif aussi. */
    #[LiveAction]
    public function chooseArea(#[LiveArg] string $area): void
    {
        $this->ensureAdmin();
        if ('' !== $area && !isset(ParisDistricts::LABELS[$area])) {
            throw new NotFoundHttpException('Unknown area.');
        }
        $this->area = $this->area === $area ? '' : $area;
        $this->limit = self::PAGE;
    }

    #[LiveAction]
    public function more(): void
    {
        $this->ensureAdmin();
        $this->limit += self::PAGE;
    }

    /** Nouvelle recherche = nouvelle première page. */
    public function onSearchUpdated(): void
    {
        $this->limit = self::PAGE;
    }

    /** Nouveau filtre = nouvelle première page (prop écrite via data-model ou URL). */
    public function onFiltersUpdated(): void
    {
        $this->limit = self::PAGE;
    }

    /** Spécialité effective : une valeur d'URL inconnue retombe sur '' (tous). */
    public function getActiveSpecialty(): string
    {
        return null !== AgentSpecialty::tryFrom($this->specialty) ? $this->specialty : '';
    }

    /** Quartier effectif : un code d'URL inconnu retombe sur '' (tous). */
    public function getActiveArea(): string
    {
        return isset(ParisDistricts::LABELS[$this->area]) ? $this->area : '';
    }

    /**
     * @return list<AgentSpecialty>
     */
    public function getSpecialtyOptions(): array
    {
        return AgentSpecialty::cases();
    }

    /**
     * Code => libellé. PHP convertit les clés numériques ('92', '93', '94')
     * en int, d'où le type de clé mixte.
     *
     * @return array<int|string, string>
     */
    public function getAreaOptions(): array
    {
        return ParisDistricts::LABELS;
    }

    /** Recherche ou filtre actif : pilote l'état vide « filtré » de la vue agents. */
    public function isFiltered(): bool
    {
        return '' !== trim($this->search)
            || '' !== $this->getActiveSpecialty()
            || '' !== $this->getActiveArea();
    }

    public function isAgenciesView(): bool
    {
        return 'agencies' === $this->view;
    }

    public function isFavoritesTab(): bool
    {
        return 'favorites' === $this->tab;
    }

    public function isMapDisplay(): bool
    {
        return $this->isAgenciesView() && 'map' === $this->display;
    }

    /** Total des favoris de la vue active (non filtré), pour le libellé de l'onglet. */
    public function getFavoriteTotal(): int
    {
        return $this->isAgenciesView()
            ? $this->countCache['agency-favorites'][''] ??= $this->agencies->countFiltered(favoritesOnly: true)
            : $this->countCache['favorites'][''] ??= $this->repository->countFiltered(favoritesOnly: true);
    }

    /** Total agents in the directory (unfiltered), for the toggle label. */
    public function getAgentTotal(): int
    {
        return $this->countCache['agents'][''] ??= $this->repository->countFiltered();
    }

    /** Total agencies in the directory (unfiltered), for the toggle label. */
    public function getAgencyTotal(): int
    {
        return $this->countCache['agencies'][''] ??= $this->agencies->countFiltered();
    }

    /** Rows in the active view after the search and filters, for the count line. */
    public function getTotalCount(): int
    {
        $needle = trim($this->search);
        if ($this->isAgenciesView()) {
            // La vue agences ignore les filtres spécialité / quartier.
            return $this->isFavoritesTab()
                ? $this->countCache['agency-favorites'][$needle] ??= $this->agencies->countFiltered($needle, favoritesOnly: true)
                : $this->countCache['agencies'][$needle] ??= $this->agencies->countFiltered($needle);
        }

        [$specialty, $area] = [$this->getActiveSpecialty(), $this->getActiveArea()];
        $key = $needle.'|'.$specialty.'|'.$area;

        return $this->isFavoritesTab()
            ? $this->countCache['favorites'][$key] ??= $this->repository->countFiltered($needle, favoritesOnly: true, specialty: $specialty ?: null, area: $area ?: null)
            : $this->countCache['agents'][$key] ??= $this->repository->countFiltered($needle, specialty: $specialty ?: null, area: $area ?: null);
    }

    /** Lignes filtrées restantes derrière le "Voir plus". */
    public function getHiddenCount(): int
    {
        return max(0, $this->getTotalCount() - $this->limit);
    }

    /**
     * @return list<AgentSummary>
     */
    public function getAgents(): array
    {
        return $this->repository->findPagedSummaries(
            trim($this->search),
            $this->limit,
            $this->isFavoritesTab(),
            $this->getActiveSpecialty() ?: null,
            $this->getActiveArea() ?: null,
        );
    }

    /**
     * @return list<AgencySummary>
     */
    public function getAgencies(): array
    {
        return $this->agencies->findPagedSummaries(trim($this->search), $this->limit, $this->isFavoritesTab());
    }

    /**
     * Carte de la vue agences : mêmes options que la carte de la fiche
     * agence, un marker par agence active géocodée. Les markers suivent la
     * recherche et l'onglet favoris (mêmes filtres que la liste).
     */
    public function getAgenciesMap(): Map
    {
        $map = (new Map('default'))
            ->center(new Point(48.8566, 2.3522))
            ->zoom(11.2)
            ->options(new GoogleOptions(
                gestureHandling: GestureHandling::COOPERATIVE,
                mapTypeControl: false,
                streetViewControl: false,
                fullscreenControl: false,
                zoomControl: false,
            ));

        // Pin maison du site (goutte bordeaux, pastille blanche, glyphe
        // immeuble) : même signature que les cartes marketplace.
        $pinSvg = <<<'SVG'
            <svg xmlns="http://www.w3.org/2000/svg" width="39" height="50" viewBox="0 0 44 56">
                <path d="M22 0C9.85 0 0 9.85 0 22c0 16.5 22 34 22 34s22-17.5 22-34C44 9.85 34.15 0 22 0Z" fill="#71172e"/>
                <circle cx="22" cy="22" r="13" fill="white"/>
                <path d="M17.5 15.5h5v4h4.5a1.5 1.5 0 0 1 1.5 1.5v8.5h-3.5v-3h-2v3H16a1.5 1.5 0 0 1-1.5-1.5V17a1.5 1.5 0 0 1 1.5-1.5Zm.5 3.5v1.5h1.5V19H18Zm3.5 0v1.5H23V19h-1.5ZM18 22.5V24h1.5v-1.5H18Zm3.5 0V24H23v-1.5h-1.5Zm4 1.5v1.5H27V24h-1.5Z" fill="#71172e"/>
            </svg>
            SVG;

        foreach ($this->agencies->findMapMarkers(trim($this->search), $this->isFavoritesTab()) as $row) {
            $link = $this->urlGenerator->generate('admin_agency_show', [
                'adminPrefix' => $this->adminPrefix,
                'reference' => $row['reference'],
            ]);
            // Contenu maîtrisé : nom et adresse échappés, lien path() vers la fiche.
            $content = null !== $row['address'] ? '<p>'.htmlspecialchars($row['address']).'</p>' : '';
            $content .= '<p><a href="'.htmlspecialchars($link).'">'.htmlspecialchars($this->translator->trans('admin.agencies.card.open')).'</a></p>';
            $map->addMarker(new Marker(
                position: new Point($row['latitude'], $row['longitude']),
                icon: Icon::svg($pinSvg),
                title: $row['name'],
                infoWindow: new InfoWindow(
                    headerContent: htmlspecialchars($row['name']),
                    content: $content,
                ),
                id: (string) $row['id'],
            ));
        }

        return $map;
    }

    /**
     * Clé du wrapper data-live-ignore de la carte : elle change avec les
     * filtres, donc le morph remplace la zone entière et la carte se
     * reconstruit avec les markers à jour (une zone ignorée ne se met
     * jamais à jour en place).
     */
    public function getMapKey(): string
    {
        return substr(md5(trim($this->search).'|'.$this->tab), 0, 8);
    }

    /** @var array<string, array<string, int>> */
    private array $countCache = [];

    private function ensureAdmin(): void
    {
        if (!$this->security->isGranted('ROLE_SECTION_AGENTS')) {
            throw new AccessDeniedException('Admin access required.');
        }
    }
}
