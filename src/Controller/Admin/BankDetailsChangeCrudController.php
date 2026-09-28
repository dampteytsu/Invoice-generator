<?php

namespace App\Controller\Admin;

use App\Entity\BankDetailsChange;
use App\Entity\User;
use App\Service\CurrentUser;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Read-only history of payment-detail changes (values are stored masked).
 * Company admins see their own company; the super admin sees all.
 */
class BankDetailsChangeCrudController extends AbstractCrudController
{
    public function __construct(private readonly CurrentUser $current)
    {
    }

    public static function getEntityFqcn(): string
    {
        return BankDetailsChange::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Payment details change')
            ->setEntityLabelInPlural('Payment details history')
            ->setDefaultSort(['changedAt' => 'DESC'])
            ->setSearchFields(['changedBy', 'ipAddress'])
            ->setPaginatorPageSize(30);
    }

    public function configureActions(Actions $actions): Actions
    {
        // Append-only: nobody can create, edit or delete entries from the UI.
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE, Action::DETAIL)
            ->setPermission(Action::INDEX, User::ROLE_COMPANY_ADMIN);
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);
        if (!$this->current->isSuperAdmin()) {
            $qb->andWhere('entity.company = :myCompany')->setParameter('myCompany', $this->current->company()?->getId() ?? 0);
        }

        return $qb;
    }

    public function configureFields(string $pageName): iterable
    {
        yield DateTimeField::new('changedAt', 'When')->setFormat('dd MMM yyyy, HH:mm');
        if ($this->current->isSuperAdmin()) {
            yield AssociationField::new('company');
        }
        yield TextField::new('changedBy', 'By');
        yield TextField::new('ipAddress', 'IP address');
        yield TextField::new('summary', 'Changes')
            ->setSortable(false)
            ->setMaxLength(1000)
            ->formatValue(fn ($v) => str_replace("\n", '  ·  ', (string) $v));
    }
}
