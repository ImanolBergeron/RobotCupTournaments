<?php

// src/Command/CreateAdminCommand.php

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class CreateAdminCommand extends Command
{
    private EntityManagerInterface $em;
    private UserPasswordHasherInterface $passwordHasher;

    public function __construct(EntityManagerInterface $em, UserPasswordHasherInterface $passwordHasher)
    {
        parent::__construct();
        $this->em = $em;
        $this->passwordHasher = $passwordHasher;
    }

    protected function configure(): void
    {
        $this->setName('app:create-admin')
             ->setDescription('Create an admin user');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Ensure we have not admin already exist
        $existingAdmin = $this->em->getRepository(User::class)->findOneBy(['roles' => ['ROLE_ADMIN']]);

        if ($existingAdmin) {
            $output->writeln('An admin user already exists.');
            return Command::SUCCESS;
        }

        $admin = new User();
        $admin->setFirstName('admin');
        $admin->setLastName('ADMIN');
        $admin->setEmail('admin@admin.com');
        $admin->setRoles(['ROLE_ADMIN']);

        // Password
        $hashedPassword = $this->passwordHasher->hashPassword($admin, '123456');
        $admin->setPassword($hashedPassword);

        $this->em->persist($admin);
        $this->em->flush();

        $output->writeln('Admin user created successfully!');
        return Command::SUCCESS;
    }
}
