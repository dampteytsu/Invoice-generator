<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\CompanyRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * php bin/console app:create-user you@example.com "Your Name" --super-admin
 */
#[AsCommand(name: 'app:create-user', description: 'Create a user (use --super-admin for the platform owner).')]
class CreateUserCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly CompanyRepository $companies,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Login email')
            ->addArgument('name', InputArgument::OPTIONAL, 'Full name', '')
            ->addOption('super-admin', null, InputOption::VALUE_NONE, 'Can manage every company and user')
            ->addOption('company-admin', null, InputOption::VALUE_NONE, 'Can manage their company details and team')
            ->addOption('company', null, InputOption::VALUE_REQUIRED, 'ID of an existing company to join')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Password (you will be prompted if omitted)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = mb_strtolower(trim((string) $input->getArgument('email')));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $io->error('Please give a valid email address.');

            return Command::INVALID;
        }
        if ($this->users->findOneBy(['email' => $email])) {
            $io->error(sprintf('A user with email %s already exists.', $email));

            return Command::FAILURE;
        }

        $password = $input->getOption('password');
        if (!$password) {
            $q = (new Question('Password (min. 8 characters)'))->setHidden(true)->setHiddenFallback(false);
            $password = (string) $io->askQuestion($q);
        }
        if (mb_strlen((string) $password) < 8) {
            $io->error('The password must be at least 8 characters.');

            return Command::INVALID;
        }

        $user = (new User())->setEmail($email)->setFullName((string) $input->getArgument('name') ?: $email);
        $user->setPassword($this->hasher->hashPassword($user, (string) $password));

        if ($input->getOption('super-admin')) {
            $user->setRole(User::ROLE_SUPER_ADMIN);
        } elseif ($input->getOption('company-admin')) {
            $user->setRole(User::ROLE_COMPANY_ADMIN);
        }

        if ($companyId = $input->getOption('company')) {
            $company = $this->companies->find((int) $companyId);
            if (!$company) {
                $io->error(sprintf('Company #%s not found.', $companyId));

                return Command::FAILURE;
            }
            $user->setCompany($company);
        }

        $this->em->persist($user);
        $this->em->flush();

        $io->success(sprintf('Created %s (%s).', $email, $user->getRole()));

        return Command::SUCCESS;
    }
}
