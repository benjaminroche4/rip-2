<?php

namespace App\Controller\Admin;

use App\Entity\Blog;
use App\Entity\BlogCategory;
use App\Entity\BlogRedactor;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;

#[AdminDashboard(routePath: '/admin', routeName: 'app_admin_dashboard')]
class DashboardController extends AbstractDashboardController
{
    public function index(): Response
    {
        if (!$this->isGranted('ROLE_ADMIN')) {
            return $this->redirectToRoute('app_home');
        }

        $adminUrlGenerator = $this->container->get(AdminUrlGenerator::class);
        return $this->redirect($adminUrlGenerator->setController(UserCrudController::class)->generateUrl());
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('Relocation In Paris');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Home', 'fa fa-home');
        yield MenuItem::linkToCrud('Rédacteurs', 'fas fa-list', BlogRedactor::class);
        yield MenuItem::linkToCrud('Catégories', 'fas fa-list', BlogCategory::class);
        yield MenuItem::linkToCrud('Articles', 'fas fa-list', Blog::class);

    }
}
