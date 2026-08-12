<?php

declare(strict_types=1);

namespace App\Admin\Twig\Components;

use App\Admin\Repository\GlobalSearchRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Cmd+K palette: one input, live results grouped by section, each group
 * only for users granted that section. The overlay visibility is a server
 * LiveProp (the anti-morph pattern of the drawers): re-renders while
 * typing can never close it.
 */
#[AsLiveComponent(name: 'Admin:GlobalSearch', template: 'components/Admin/GlobalSearch.html.twig')]
final class GlobalSearch
{
    use DefaultActionTrait;

    private const MIN_CHARS = 2;
    private const PER_GROUP = 5;

    #[LiveProp]
    public string $adminPrefix = '';

    #[LiveProp]
    public bool $open = false;

    #[LiveProp(writable: true)]
    public string $query = '';

    public function __construct(
        private readonly GlobalSearchRepository $repository,
        private readonly Security $security,
    ) {
    }

    public function mount(string $adminPrefix): void
    {
        $this->ensureAdmin();
        $this->adminPrefix = $adminPrefix;
    }

    #[LiveAction]
    public function openSearch(): void
    {
        $this->ensureAdmin();
        $this->open = true;
    }

    #[LiveAction]
    public function closeSearch(): void
    {
        $this->ensureAdmin();
        $this->open = false;
        $this->query = '';
    }

    public function isSearchable(): bool
    {
        return \strlen(trim($this->query)) >= self::MIN_CHARS;
    }

    /**
     * Groups in sidebar order, sections the user cannot access silently
     * skipped. Empty groups are dropped: the palette only shows matches.
     *
     * @return list<array{key: string, labelKey: string, icon: string, hits: list<\App\Admin\Domain\SearchHit>, moreRoute: ?string}>
     */
    public function getGroups(): array
    {
        $this->ensureAdmin();
        if (!$this->open || !$this->isSearchable()) {
            return [];
        }

        $q = trim($this->query);
        $groups = [];
        $sections = [
            // "moreRoute" only where the target list mirrors ?search in a URL prop.
            ['key' => 'contacts', 'role' => 'ROLE_SECTION_CONTACTS', 'labelKey' => 'admin.nav.contacts', 'icon' => 'lucide:inbox', 'fetch' => $this->repository->contacts(...), 'moreRoute' => 'admin_contacts'],
            ['key' => 'dossiers', 'role' => 'ROLE_SECTION_DOSSIERS', 'labelKey' => 'admin.nav.dossiers', 'icon' => 'lucide:folder-open', 'fetch' => $this->repository->dossiers(...), 'moreRoute' => 'admin_dossiers'],
            ['key' => 'visits', 'role' => 'ROLE_SECTION_VISITS', 'labelKey' => 'admin.nav.visits', 'icon' => 'lucide:calendar-check', 'fetch' => $this->repository->visits(...), 'moreRoute' => null],
            ['key' => 'agents', 'role' => 'ROLE_SECTION_AGENTS', 'labelKey' => 'admin.nav.agents', 'icon' => 'lucide:building-2', 'fetch' => $this->repository->agents(...), 'moreRoute' => 'admin_agents'],
            ['key' => 'users', 'role' => 'ROLE_ADMIN', 'labelKey' => 'admin.nav.users', 'icon' => 'lucide:users', 'fetch' => $this->repository->users(...), 'moreRoute' => null],
        ];

        foreach ($sections as $section) {
            if (!$this->security->isGranted($section['role'])) {
                continue;
            }
            $hits = ($section['fetch'])($q, self::PER_GROUP);
            if ([] === $hits) {
                continue;
            }
            $groups[] = [
                'key' => $section['key'],
                'labelKey' => $section['labelKey'],
                'icon' => $section['icon'],
                'hits' => $hits,
                // A full page hints at more results behind the cap.
                'moreRoute' => self::PER_GROUP === \count($hits) ? $section['moreRoute'] : null,
            ];
        }

        return $groups;
    }

    private function ensureAdmin(): void
    {
        if (!$this->security->isGranted('ROLE_STAFF')) {
            throw new AccessDeniedException('Back-office access required.');
        }
    }
}
