<?php

declare(strict_types=1);

namespace App\Dossier\Twig\Components;

use App\Auth\Entity\User;
use App\Dossier\Domain\DossierManagerView;
use App\Dossier\Entity\Dossier;
use App\Dossier\Repository\DossierRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * "Responsable de dossier" chip in the dossier detail header: shows the
 * assigned staff member and lets the admin assign, change or unassign one
 * from a dropdown of admin/editor users.
 */
#[AsLiveComponent(name: 'Dossier:ManagerPicker', template: 'components/Dossier/ManagerPicker.html.twig')]
final class ManagerPicker
{
    use DefaultActionTrait;

    #[LiveProp]
    public int $dossierId = 0;

    public function __construct(
        private readonly DossierRepository $repository,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {
    }

    public function mount(): void
    {
        $this->ensureAdmin();
    }

    public function getManager(): ?DossierManagerView
    {
        $manager = $this->dossier()->getManager();

        return null !== $manager ? $this->view($manager) : null;
    }

    /**
     * Assignable staff: users carrying an admin-space role.
     *
     * @return list<DossierManagerView>
     */
    public function getChoices(): array
    {
        /** @var list<User> $users */
        $users = $this->em->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.roles LIKE :admin OR u.roles LIKE :editor')
            ->setParameter('admin', '%ROLE_ADMIN%')
            ->setParameter('editor', '%ROLE_EDITOR%')
            ->orderBy('u.firstName', 'ASC')
            ->addOrderBy('u.lastName', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map($this->view(...), $users);
    }

    #[LiveAction]
    public function chooseManager(#[LiveArg] int $key): void
    {
        $this->ensureAdmin();

        $user = $this->em->find(User::class, $key);
        if (null === $user || [] === array_intersect(['ROLE_ADMIN', 'ROLE_EDITOR'], $user->getRoles())) {
            throw new NotFoundHttpException('Assignable user not found.');
        }

        $this->dossier()->setManager($user);
        $this->em->flush();
    }

    #[LiveAction]
    public function removeManager(): void
    {
        $this->ensureAdmin();
        $this->dossier()->setManager(null);
        $this->em->flush();
    }

    private function view(User $user): DossierManagerView
    {
        $fullName = trim(($user->getFirstName() ?? '').' '.($user->getLastName() ?? ''));

        return new DossierManagerView(
            id: (int) $user->getId(),
            fullName: '' !== $fullName ? $fullName : (string) $user->getEmail(),
            email: (string) $user->getEmail(),
            avatarFilename: $user->getAvatarFilename(),
        );
    }

    private function dossier(): Dossier
    {
        return $this->repository->find($this->dossierId)
            ?? throw new NotFoundHttpException('Dossier not found.');
    }

    private function ensureAdmin(): void
    {
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            throw new AccessDeniedException('Admin access required.');
        }
    }
}
