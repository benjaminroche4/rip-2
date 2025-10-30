<?php

namespace App\DataFixtures;

use App\Factory\ContactFactory;
use App\Factory\UserFactory;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * php bin/console doctrine:fixtures:load
 */
class AppFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        UserFactory::createMany(5);
        ContactFactory::createMany(5);
    }
}
