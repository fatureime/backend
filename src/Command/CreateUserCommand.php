<?php

namespace App\Command;

use App\Entity\Business;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\TenantRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:create-user',
    description: 'Create a new user with email and password'
)]
class CreateUserCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $userRepository,
        private TenantRepository $tenantRepository,
        private UserPasswordHasherInterface $passwordHasher
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = 'ylberprapashtica@faureime.de';
        $password = 'Test123!';

        // Check if user already exists
        $existingUser = $this->userRepository->findOneByEmail($email);
        if ($existingUser) {
            $io->warning(sprintf('User with email %s already exists!', $email));
            return Command::FAILURE;
        }

        // Create or find tenant for this user
        $isNewTenant = false;
        $tenant = $this->tenantRepository->findOneBy(['name' => 'Tenant for ' . $email]);
        if (!$tenant) {
            $tenant = new Tenant();
            $tenant->setName('Tenant for ' . $email);
            $tenant->setHasPaid(false);
            $tenant->setIsAdmin(false);
            $this->entityManager->persist($tenant);
            $isNewTenant = true;
            $io->info('Created new tenant for user');
        }

        // Create user
        $user = new User();
        $user->setEmail($email);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $user->setRoles(['ROLE_USER']);
        $user->setEmailVerified(true); // Set as verified for convenience
        $user->setTenant($tenant);
        $user->setIsActive(true);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        // If a new tenant was created, automatically create a business for it and set as issuer
        if ($isNewTenant && $tenant->getBusinesses()->isEmpty()) {
            $business = new Business();
            $business->setBusinessName($tenant->getName()); // Default to tenant name
            $business->setCreatedBy($user);
            $business->setTenant($tenant);
            $this->entityManager->persist($business);
            $this->entityManager->flush();
            
            // Set this business as the issuer business for the tenant
            $tenant->setIssuerBusiness($business);
            $this->entityManager->flush();
            $io->info('Created business for new tenant and set as issuer business');
        }

        $io->success(sprintf('User created successfully! Email: %s', $email));

        return Command::SUCCESS;
    }
}
