<?php

namespace App\Controller\Admin;

use App\Entity\Invoice;
use App\Entity\InvoiceItem;
use App\Entity\TaxMode;
use App\Form\InvoiceItemType;
use App\Security\TenantVoter;
use App\Service\CurrentUser;
use App\Service\InvoiceNumberGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use Symfony\Component\HttpFoundation\Response;

class InvoiceCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly CurrentUser $current,
        private readonly InvoiceNumberGenerator $numbers,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Invoice::class;
    }

    /** Each company only ever sees its own invoices (the super admin sees all). */
    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);
        if (!$this->current->isSuperAdmin()) {
            $qb->andWhere('entity.company = :myCompany')->setParameter('myCompany', $this->current->company()?->getId() ?? 0);
        }

        return $qb;
    }

    /** Users who haven't set up a company yet are sent to do that first. */
    public function new(AdminContext $context): KeyValueStore|Response
    {
        if ($this->current->needsOnboarding()) {
            $this->addFlash('info', 'Set up your company first – its details appear on every invoice.');

            return $this->redirectToRoute('app_my_company');
        }

        return parent::new($context);
    }

    /** New invoices start pre-filled: next number, today's date, due date and tax rates from the company. */
    public function createEntity(string $entityFqcn): Invoice
    {
        $today = new \DateTimeImmutable('today');
        $invoice = (new Invoice())->setInvoiceDate($today)->setDueDate($today->modify('+30 days'));

        $company = $this->current->company();
        if ($company && !$this->current->isSuperAdmin()) {
            $invoice
                ->setCompany($company)
                ->applySettings($company)
                ->setInvoiceNumber($this->numbers->next($company, $today))
                ->setDueDate($today->modify(sprintf('+%d days', $company->getDefaultDueDays())));
        }

        $invoice->addItem(new InvoiceItem());

        return $invoice;
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof Invoice) {
            $this->prepare($entityInstance);
        }
        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof Invoice) {
            $this->prepare($entityInstance);
        }
        parent::updateEntity($entityManager, $entityInstance);
    }

    private function prepare(Invoice $invoice): void
    {
        // Never trust the form for the tenant: company users always write to their own company.
        if (!$this->current->isSuperAdmin()) {
            $invoice->setCompany($this->current->company());
        }
        if ($invoice->getInvoiceNumber() === '' && $invoice->getCompany()) {
            $invoice->setInvoiceNumber($this->numbers->next($invoice->getCompany(), $invoice->getInvoiceDate()));
        }
        $invoice->recalculate();
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityPermission(TenantVoter::ACCESS)
            ->setEntityLabelInSingular('Invoice')
            ->setEntityLabelInPlural('Invoices')
            ->setPageTitle(Crud::PAGE_INDEX, 'Invoices')
            ->setPageTitle(Crud::PAGE_NEW, 'New invoice')
            ->setPageTitle(Crud::PAGE_EDIT, fn (Invoice $i) => sprintf('Edit invoice %s', $i->getInvoiceNumber()))
            ->setDefaultSort(['invoiceDate' => 'DESC', 'id' => 'DESC'])
            ->setSearchFields(['invoiceNumber', 'billToName', 'billToContact'])
            ->setDateFormat('dd MMM yyyy')
            ->setPaginatorPageSize(25);
    }

    public function configureAssets(Assets $assets): Assets
    {
        return $assets->addJsFile('js/invoice-form.js');
    }

    public function configureActions(Actions $actions): Actions
    {
        $view = Action::new('viewInvoice', 'View / Print', 'fa fa-print')
            ->linkToRoute('invoice_print', fn (Invoice $i) => ['id' => $i->getId()])
            ->setHtmlAttributes(['target' => '_blank']);

        $pdf = Action::new('downloadPdf', 'PDF', 'fa fa-file-pdf')
            ->linkToRoute('invoice_pdf', fn (Invoice $i) => ['id' => $i->getId(), 'download' => 1]);

        return $actions
            ->disable(Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $view)
            ->add(Crud::PAGE_INDEX, $pdf)
            ->add(Crud::PAGE_EDIT, $view)
            ->add(Crud::PAGE_EDIT, $pdf)
            ->add(Crud::PAGE_NEW, Action::SAVE_AND_CONTINUE)
            ->reorder(Crud::PAGE_INDEX, ['viewInvoice', 'downloadPdf', Action::EDIT, Action::DELETE]);
    }

    public function configureFilters(Filters $filters): Filters
    {
        if ($this->current->isSuperAdmin()) {
            $filters->add(EntityFilter::new('company'));
        }

        return $filters
            ->add(ChoiceFilter::new('status')->setChoices(Invoice::STATUSES))
            ->add(DateTimeFilter::new('invoiceDate'))
            ->add(DateTimeFilter::new('dueDate'));
    }

    public function configureFields(string $pageName): iterable
    {
        $money = fn ($value, Invoice $i) => sprintf('%s %s', $i->getCurrency(), number_format((float) $value, 2));

        $super = $this->current->isSuperAdmin();

        // ── Invoice details ─────────────────────────────────
        yield FormField::addFieldset('Invoice details')->setIcon('fa fa-file-invoice');
        if ($super) {
            yield AssociationField::new('company', 'Issued by')->setColumns(12)
                ->setHelp('Super admin only. Tax rates below are not changed automatically when you pick a company.');
        }
        yield TextField::new('invoiceNumber', 'Invoice #')->setColumns(3)->setRequired(false)
            ->setHelp('Leave blank to auto-number.');
        yield DateField::new('invoiceDate', 'Invoice date')->setColumns(3);
        yield DateField::new('dueDate', 'Due date')->setColumns(3);
        yield ChoiceField::new('status')->setColumns(3)
            ->setChoices(Invoice::STATUSES)
            ->renderAsBadges([
                Invoice::STATUS_DRAFT => 'secondary',
                Invoice::STATUS_SENT => 'info',
                Invoice::STATUS_PAID => 'success',
                Invoice::STATUS_CANCELLED => 'danger',
            ]);

        // ── Bill to ─────────────────────────────────────────
        yield FormField::addFieldset('Bill to')->setIcon('fa fa-user');
        yield TextField::new('billToName', 'Bill to')->setColumns(6);
        yield TextField::new('billToContact', 'Contact (phone / email)')->setColumns(6)->hideOnIndex();
        yield TextareaField::new('billToAddress', 'Address')->setColumns(6)->setNumOfRows(3)->hideOnIndex();
        yield TextField::new('billToTin', 'Customer TIN')->setColumns(6)->hideOnIndex()
            ->setHelp('Optional – the customer\'s GRA Taxpayer Identification Number.');

        // ── Items ───────────────────────────────────────────
        yield FormField::addFieldset('Items')->setIcon('fa fa-list');
        yield CollectionField::new('items', false)
            ->onlyOnForms()
            ->setEntryType(InvoiceItemType::class)
            ->setEntryIsComplex(true)
            ->allowAdd()
            ->allowDelete()
            ->renderExpanded()
            ->setFormTypeOption('by_reference', false)
            ->setColumns(12);

        // ── Taxes & levies ──────────────────────────────────
        yield FormField::addFieldset('Taxes & levies')->setIcon('fa fa-percent')
            ->setHelp('Pre-filled from Company settings. Changing them here only affects this invoice.');
        yield BooleanField::new('taxable', 'Charge VAT & levies')->onlyOnForms()->setColumns(4);
        yield ChoiceField::new('taxMode', 'Calculation method')->onlyOnForms()->setColumns(5)
            ->setChoices(TaxMode::choices());
        yield TextField::new('currency')->onlyOnForms()->setColumns(3);
        yield NumberField::new('nhilRate', 'NHIL %')->onlyOnForms()->setColumns(3)->setNumDecimals(2)->setStoredAsString();
        yield NumberField::new('getfundRate', 'GETFund %')->onlyOnForms()->setColumns(3)->setNumDecimals(2)->setStoredAsString();
        yield NumberField::new('covidRate', 'COVID-19 levy %')->onlyOnForms()->setColumns(3)->setNumDecimals(2)->setStoredAsString()
            ->setHelp('Abolished from 1 Jan 2026 – leave at 0.');
        yield NumberField::new('vatRate', 'VAT %')->onlyOnForms()->setColumns(3)->setNumDecimals(2)->setStoredAsString();

        yield TextareaField::new('notes', 'Notes (shown on invoice)')->onlyOnForms()->setNumOfRows(2)->setColumns(12);

        // ── Index columns ───────────────────────────────────
        yield TextField::new('subtotal')->onlyOnIndex()->formatValue($money)->setTextAlign('right');
        yield TextField::new('vatAmount', 'VAT + levies')->onlyOnIndex()->setTextAlign('right')
            ->formatValue(fn ($v, Invoice $i) => sprintf('%s %s', $i->getCurrency(), number_format((float) $i->getTotal() - (float) $i->getSubtotal(), 2)));
        yield TextField::new('total')->onlyOnIndex()->formatValue($money)->setTextAlign('right');
    }
}
