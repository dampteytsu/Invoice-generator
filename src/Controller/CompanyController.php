<?php

namespace App\Controller;

use App\Controller\Admin\CompanyCrudController;
use App\Controller\Admin\DashboardController;
use App\Service\CurrentUser;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

class CompanyController extends AbstractController
{
    /**
     * "My company" entry point:
     *  - no company yet → the set-up form
     *  - company admin  → edit own company
     *  - super admin    → list of all companies
     */
    // Deliberately outside /admin: with EasyAdmin pretty URLs, /admin/company is the
    // Companies CRUD index (super admin only) and would shadow this route.
    #[Route('/my-company', name: 'app_my_company')]
    public function __invoke(CurrentUser $current, AdminUrlGenerator $urls): RedirectResponse
    {
        // unsetAll(): drop the current query string (e.g. ?routeName=...); otherwise
        // EasyAdmin would treat the edit page as a plain route and throw "Cannot get entity outside of a CRUD context".
        $urls->unsetAll()->setDashboard(DashboardController::class)->setController(CompanyCrudController::class);

        if ($current->isSuperAdmin()) {
            return $this->redirect($urls->setAction(Action::INDEX)->generateUrl());
        }

        $company = $current->company();
        if (!$company) {
            return $this->redirect($urls->setAction(Action::NEW)->generateUrl());
        }

        if (!$current->isCompanyAdmin()) {
            $this->addFlash('warning', 'Only your company administrator can change the company details.');

            return $this->redirectToRoute('admin');
        }

        return $this->redirect($urls->setAction(Action::EDIT)->setEntityId($company->getId())->generateUrl());
    }
}
