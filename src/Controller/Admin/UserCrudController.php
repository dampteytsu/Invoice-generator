<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Security\TenantVoter;
use App\Service\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;
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
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Users.
 *  - Super admin: every user, any role, any company.
 *  - Company admin: only the users of their own company ("Team").
 */
class UserCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly CurrentUser $current,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        $super = $this->current->isSuperAdmin();

        return $crud
            ->setEntityLabelInSingular('User')
            ->setEntityLabelInPlural($super ? 'Users' : 'Team')
            ->setEntityPermission(TenantVoter::ACCESS)
            ->setSearchFields(['fullName', 'email'])
            ->setDefaultSort(['fullName' => 'ASC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::DETAIL, Action::BATCH_DELETE)
            ->setPermission(Action::INDEX, User::ROLE_COMPANY_ADMIN)
            ->setPermission(Action::NEW, User::ROLE_COMPANY_ADMIN)
            ->setPermission(Action::EDIT, User::ROLE_COMPANY_ADMIN)
            ->setPermission(Action::DELETE, User::ROLE_COMPANY_ADMIN);
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);
        if (!$this->current->isSuperAdmin()) {
            $qb->andWhere('entity.company = :myCompany')->setParameter('myCompany', $this->current->company()?->getId() ?? 0);
        }

        return $qb;
    }

    public function createEntity(string $entityFqcn): User
    {
        $user = new User();
        if (!$this->current->isSuperAdmin()) {
            $user->setCompany($this->current->company());
        }

        return $user;
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof User) {
            $this->enforceTenantRules($entityInstance);
            $this->hashPassword($entityInstance);
        }
        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof User) {
            $this->enforceTenantRules($entityInstance);
            $this->hashPassword($entityInstance);
        }
        parent::updateEntity($entityManager, $entityInstance);
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance === $this->current->user()) {
            $this->addFlash('danger', 'You cannot delete your own account.');

            return;
        }
        parent::deleteEntity($entityManager, $entityInstance);
    }

    /** Company admins can only manage their own company, and nobody can lock themself out. */
    private function enforceTenantRules(User $user): void
    {
        $me = $this->current->user();

        if (!$this->current->isSuperAdmin()) {
            $user->setCompany($this->current->company());
            if ($user->isSuperAdmin()) {
                $user->setRole(User::ROLE_USER);
            }
        }

        if ($user === $me) {
            $user->setActive(true);
            $user->setRoles($me->isSuperAdmin() ? [User::ROLE_SUPER_ADMIN] : [User::ROLE_COMPANY_ADMIN]);
        }
    }

    private function hashPassword(User $user): void
    {
        if ($user->getPlainPassword()) {
            $user->setPassword($this->hasher->hashPassword($user, $user->getPlainPassword()));
            $user->eraseCredentials();
        }
    }

    public function configureFields(string $pageName): iterable
    {
        $super = $this->current->isSuperAdmin();

        $roles = [
            'Member – creates invoices' => User::ROLE_USER,
            'Company admin – also manages company details & team' => User::ROLE_COMPANY_ADMIN,
        ];
        if ($super) {
            $roles['Super admin – manages all companies'] = User::ROLE_SUPER_ADMIN;
        }

        yield TextField::new('fullName', 'Name')->setColumns(6);
        yield EmailField::new('email')->setColumns(6);

        yield ChoiceField::new('role')
            ->setSortable(false)
            ->setChoices($roles)
            ->setColumns(6)
            ->renderAsBadges([
                User::ROLE_USER => 'secondary',
                User::ROLE_COMPANY_ADMIN => 'primary',
                User::ROLE_SUPER_ADMIN => 'danger',
            ]);

        if ($super) {
            yield AssociationField::new('company')
                ->setColumns(6)
                ->setRequired(false)
                ->setHelp('Leave empty to let the user set up their own company when they first sign in.');
        }

        yield BooleanField::new('active')->setColumns(12)->renderAsSwitch(false)
            ->setHelp('Inactive users cannot sign in.');

        yield TextField::new('plainPassword', Crud::PAGE_NEW === $pageName ? 'Password' : 'New password')
            ->onlyOnForms()
            ->setFormType(RepeatedType::class)
            ->setFormTypeOptions([
                'type' => PasswordType::class,
                'first_options' => ['label' => Crud::PAGE_NEW === $pageName ? 'Password' : 'New password', 'attr' => ['autocomplete' => 'new-password']],
                'second_options' => ['label' => 'Repeat password', 'attr' => ['autocomplete' => 'new-password']],
                'invalid_message' => 'The passwords do not match.',
                'required' => Crud::PAGE_NEW === $pageName,
                'constraints' => Crud::PAGE_NEW === $pageName ? [new NotBlank(message: 'Please choose a password.')] : [],
            ])
            ->setColumns(12)
            ->setHelp(Crud::PAGE_EDIT === $pageName ? 'Leave blank to keep the current password.' : 'At least 8 characters.');

        yield DateTimeField::new('createdAt', 'Created')->onlyOnIndex()->setFormat('dd MMM yyyy');
    }
}
