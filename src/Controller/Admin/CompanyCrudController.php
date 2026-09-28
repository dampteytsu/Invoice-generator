<?php

namespace App\Controller\Admin;

use App\Entity\Company;
use App\Entity\TaxMode;
use App\Entity\User;
use App\Security\TenantVoter;
use App\Service\BankDetailsAudit;
use App\Service\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ColorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\Constraints\Image;

/**
 * Companies (tenants).
 *  - Super admin: sees, creates and deletes every company.
 *  - A signed-in user with no company: sets one up and becomes its company admin.
 *  - Company admin: edits only their own company.
 *
 * Payment details (bank / Mobile Money) are visible and editable ONLY by the
 * company's own users. The super admin never sees or changes them, so a
 * compromised platform account cannot redirect customers' payments.
 */
class CompanyCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly CurrentUser $current,
        private readonly AdminUrlGenerator $urls,
        private readonly BankDetailsAudit $audit,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Company::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Company')
            ->setEntityLabelInPlural('Companies')
            ->setPageTitle(Crud::PAGE_NEW, fn () => $this->current->isSuperAdmin() ? 'New company' : 'Set up your company')
            ->setPageTitle(Crud::PAGE_EDIT, fn (Company $c) => $this->current->isSuperAdmin() ? 'Edit '.$c->getName() : 'Company settings')
            ->setEntityPermission(TenantVoter::ACCESS)
            ->setSearchFields(['name', 'email', 'phone', 'tin'])
            ->setDefaultSort(['name' => 'ASC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::DETAIL, Action::BATCH_DELETE)
            ->setPermission(Action::INDEX, User::ROLE_SUPER_ADMIN)
            ->setPermission(Action::DELETE, User::ROLE_SUPER_ADMIN)
            ->setPermission(Action::EDIT, User::ROLE_COMPANY_ADMIN)
            ->setPermission(Action::SAVE_AND_RETURN, User::ROLE_SUPER_ADMIN)
            ->setPermission(Action::SAVE_AND_ADD_ANOTHER, User::ROLE_SUPER_ADMIN)
            ->add(Crud::PAGE_NEW, Action::SAVE_AND_CONTINUE)
            ->update(Crud::PAGE_NEW, Action::SAVE_AND_CONTINUE, fn (Action $a) => $a->setLabel('Save company'));
    }

    /** Defence in depth: non-super-admins can only ever query their own company. */
    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);
        if (!$this->current->isSuperAdmin()) {
            $qb->andWhere('entity.id = :myCompany')->setParameter('myCompany', $this->current->company()?->getId() ?? 0);
        }

        return $qb;
    }

    /** A regular user can only set up one company. */
    public function new(AdminContext $context): KeyValueStore|Response
    {
        if (!$this->current->isSuperAdmin() && $this->current->company()) {
            $this->addFlash('info', 'You already belong to a company.');

            return $this->redirectToRoute('app_my_company');
        }

        return parent::new($context);
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof Company) {
            // First set-up: log the initial payment details (no alert email needed).
            $this->audit->record($entityInstance, $this->audit->detectChanges($entityInstance));
        }
        parent::persistEntity($entityManager, $entityInstance);

        // The person who sets up a company becomes its administrator.
        $user = $this->current->user();
        if ($entityInstance instanceof Company && $user && !$user->isSuperAdmin()) {
            $user->setCompany($entityInstance);
            if (!$user->isCompanyAdmin()) {
                $user->addRole(User::ROLE_COMPANY_ADMIN);
            }
            $entityManager->flush();
        }
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $entry = null;
        if ($entityInstance instanceof Company) {
            // Must run before flush, while Doctrine still holds the old values.
            $entry = $this->audit->record($entityInstance, $this->audit->detectChanges($entityInstance));
            $entityInstance->setConfirmPassword(null);
        }

        parent::updateEntity($entityManager, $entityInstance);

        if ($entry) {
            $this->audit->notify($entry);
            $this->addFlash('warning', 'Your payment details were changed. All company admins have been notified by email.');
        }
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof Company) {
            foreach ($entityInstance->getUsers() as $member) {
                $member->setCompany(null);
            }
        }
        parent::deleteEntity($entityManager, $entityInstance);
    }

    protected function getRedirectResponseAfterSave(AdminContext $context, string $action): RedirectResponse
    {
        if ($this->current->isSuperAdmin()) {
            return parent::getRedirectResponseAfterSave($context, $action);
        }

        if ($context->getCrud()?->getCurrentPage() === Crud::PAGE_NEW) {
            $this->addFlash('success', 'Your company is set up. You can now create invoices.');

            return $this->redirectToRoute('admin');
        }

        $this->addFlash('success', 'Company details saved.');

        return $this->redirect($this->urls
            ->unsetAll()
            ->setController(self::class)
            ->setAction(Action::EDIT)
            ->setEntityId($context->getEntity()->getPrimaryKeyValue())
            ->generateUrl());
    }

    public function configureFields(string $pageName): iterable
    {
        yield FormField::addFieldset('Company')->setIcon('fa fa-building');
        yield TextField::new('name', 'Company name')->setColumns(6);
        yield TextField::new('tin', 'TIN')->setColumns(6)
            ->setHelp('GRA Taxpayer Identification Number – printed on every invoice.');
        yield TextareaField::new('address')->setColumns(6)->setNumOfRows(4)->hideOnIndex();
        yield ImageField::new('logo')
            ->setColumns(6)
            ->setBasePath('uploads/logo')
            ->setUploadDir('public/uploads/logo')
            ->setUploadedFileNamePattern('logo-[randomhash].[extension]')
            ->setFileConstraints(new Image(maxSize: '2M'))
            ->setRequired(false)
            ->setHelp('PNG or JPG, ideally on a white/transparent background. Max 2 MB.');

        yield ColorField::new('brandColor', 'Invoice colour')
            ->setColumns(6)
            ->showValue()
            ->setHelp('Used for the title, table header, total bar and headings on your invoices and PDFs. Dark colours work best.');

        yield FormField::addFieldset('Contact')->setIcon('fa fa-phone');
        yield TextField::new('phone')->setColumns(4);
        yield EmailField::new('email')->setColumns(4);
        yield TextField::new('website')->setColumns(4)->hideOnIndex();

        if ($this->current->isSuperAdmin()) {
            // Not rendered at all → cannot be read or submitted by the super admin.
            yield FormField::addFieldset('Bank details')->setIcon('fa fa-lock')->onlyOnForms()
                ->setHelp('Payment details are private to the company. Only its own administrators can view or change them.');
        } else {
            $bank = FormField::addFieldset('Bank details')->setIcon('fa fa-building-columns');
            if (Crud::PAGE_EDIT === $pageName) {
                $bank->setHelp('For your security, changing these requires your password, and all company admins are notified by email.');
            }
            yield $bank;
            yield TextField::new('bankName', 'Bank')->setColumns(4)->hideOnIndex();
            yield TextField::new('bankAccountName', 'Account name')->setColumns(4)->hideOnIndex();
            yield TextField::new('bankAccountNumber', 'Account number')->setColumns(4)->hideOnIndex();
            yield TextField::new('bankBranch', 'Branch')->setColumns(4)->hideOnIndex();
            yield TextField::new('swiftCode', 'SWIFT / Sort code')->setColumns(4)->hideOnIndex();
            yield TextField::new('mobileMoney', 'Mobile Money')->setColumns(4)->hideOnIndex()
                ->setHelp('Optional, e.g. "MTN MoMo 024 000 0000 (My Company Ltd)".');
            yield TextField::new('confirmPassword', 'Your password (only needed if you change the details above)')
                ->onlyWhenUpdating()
                ->setFormType(PasswordType::class)
                ->setFormTypeOption('attr', ['autocomplete' => 'current-password'])
                ->setRequired(false)
                ->setColumns(6);
        }

        yield FormField::addFieldset('Terms & conditions')->setIcon('fa fa-scale-balanced');
        yield TextareaField::new('terms', false)->setNumOfRows(5)->setColumns(12)->hideOnIndex();

        yield FormField::addFieldset('Invoice defaults')->setIcon('fa fa-sliders')
            ->setHelp('Copied onto each new invoice. Existing invoices keep the rates they were created with.');
        yield TextField::new('invoicePrefix', 'Invoice number prefix')->setColumns(4)->hideOnIndex()
            ->setHelp('INV → INV-2026-0001');
        yield TextField::new('currency')->setColumns(4)->hideOnIndex();
        yield IntegerField::new('defaultDueDays', 'Payment due (days)')->setColumns(4)->hideOnIndex();
        yield ChoiceField::new('taxMode', 'Tax calculation method')->setChoices(TaxMode::choices())->setColumns(12)->hideOnIndex()
            ->setHelp('Since 1 Jan 2026 (VAT Act 2025, Act 1151) VAT, NHIL and GETFund are all charged on the same value (20% total). Choose the pre-2026 method only for older invoices.');
        yield NumberField::new('nhilRate', 'NHIL %')->setNumDecimals(2)->setStoredAsString()->setColumns(3)->hideOnIndex();
        yield NumberField::new('getfundRate', 'GETFund levy %')->setNumDecimals(2)->setStoredAsString()->setColumns(3)->hideOnIndex();
        yield NumberField::new('covidRate', 'COVID-19 levy %')->setNumDecimals(2)->setStoredAsString()->setColumns(3)->hideOnIndex();
        yield NumberField::new('vatRate', 'VAT %')->setNumDecimals(2)->setStoredAsString()->setColumns(3)->hideOnIndex();

        // Super-admin list extras
        yield IntegerField::new('userCount', 'Users')->onlyOnIndex()->setSortable(false);
        yield IntegerField::new('invoiceCount', 'Invoices')->onlyOnIndex()->setSortable(false);
        yield DateTimeField::new('createdAt', 'Created')->onlyOnIndex()->setFormat('dd MMM yyyy');
    }
}
