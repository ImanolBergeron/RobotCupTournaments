<?php

namespace App\DataFixtures;

use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture
{
    private $passwordHasher;

    public function __construct(UserPasswordHasherInterface $passwordHasher)
    {
        $this->passwordHasher = $passwordHasher;
    }

    public function load(ObjectManager $manager): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $user = new User();
            $user->setEmail($i . '@' . $i . '.com');
            $user->setRoles(['ROLE_USER']);
            $user->setFirstName((string)$i);
            $user->setLastName((string)$i);

            $hashedPassword = $this->passwordHasher->hashPassword($user, '123456');
            $user->setPassword($hashedPassword);

            $manager->persist($user);
        }

        $orga = new User();
        $orga->setEmail('orga@orga.com');
        $orga->setRoles(['ROLE_ORGA']);
        $orga->setFirstName('orga');
        $orga->setLastName('orga');

        $hashedPassword = $this->passwordHasher->hashPassword($orga, '123456');
        $orga->setPassword($hashedPassword);

        $manager->persist($orga);

        $manager->flush();
    }
}
