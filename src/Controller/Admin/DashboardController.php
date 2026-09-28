<?php

namespace App\Controller\Admin;

use App\Entity\BankDetailsChange;
use App\Entity\Company;
use App\Entity\Invoice;
use App\Entity\User;
use App\Repository\InvoiceRepository;
use App\Service\CurrentUser;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Config\UserMenu;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;

class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly CurrentUser $current,
    ) {
    }

    #[Route('/admin', name: 'admin')]
    public function index(): Response
    {
        if ($this->current->needsOnboarding()) {
            $this->addFlash('info', 'Welcome! Start by setting up your company – its details appear on every invoice.');

            return $this->redirectToRoute('app_my_company');
        }

        $company = $this->current->isSuperAdmin() ? null : $this->current->company();

        return $this->render('admin/dashboard.html.twig', [
            'stats' => $this->invoices->getStats($company),
            'recent' => $this->invoices->findRecent($company),
            'company' => $company,
            'is_super_admin' => $this->current->isSuperAdmin(),
            'is_company_admin' => $this->current->isCompanyAdmin(),
        ]);
    }

    public function configureDashboard(): Dashboard
    {
        $company = $this->current->company();
        $name = !$this->current->isSuperAdmin() && $company ? htmlspecialchars($company->getName()) : 'Invoicer';

        return Dashboard::new()
            ->setTitle('<i class="fa fa-file-invoice-dollar"></i> '.$name)
            ->renderContentMaximized();
    }

    public function configureAssets(): Assets
    {
        return Assets::new()->addCssFile('css/admin.css');
    }

    public function configureUserMenu(UserInterface $user): UserMenu
    {
        $menu = parent::configureUserMenu($user);
        if ($user instanceof User) {
            $menu->setName($user->getFullName() ?: $user->getEmail());
        }

        return $menu;
    }

    public function configureMenuItems(): iterable
    {
        $super = $this->current->isSuperAdmin();

        yield MenuItem::linkToDashboard('Dashboard', 'fa fa-gauge');

        yield MenuItem::section('Billing');
        yield MenuItem::linkToCrud('Invoices', 'fa fa-file-invoice', Invoice::class);
        yield MenuItem::linkToCrud('New invoice', 'fa fa-plus', Invoice::class)->setAction('new');

        if (!$super && $this->current->isCompanyAdmin()) {
            yield MenuItem::section('My company');
            yield MenuItem::linkToUrl('Company settings', 'fa fa-building', $this->generateUrl('app_my_company'));
            yield MenuItem::linkToCrud('Team', 'fa fa-users', User::class);
            yield MenuItem::linkToCrud('Payment details history', 'fa fa-clock-rotate-left', BankDetailsChange::class);
        }

        if ($super) {
            yield MenuItem::section('Administration');
            yield MenuItem::linkToCrud('Companies', 'fa fa-building', Company::class);
            yield MenuItem::linkToCrud('Users', 'fa fa-users', User::class);
            yield MenuItem::linkToCrud('Payment details history', 'fa fa-clock-rotate-left', BankDetailsChange::class);
        }
    }
}
