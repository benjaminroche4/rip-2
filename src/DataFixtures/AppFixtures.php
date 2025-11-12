<?php

namespace App\DataFixtures;

use App\Factory\BlogCategoryFactory;
use App\Factory\BlogFactory;
use App\Factory\BlogRedactorFactory;
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
        BlogCategoryFactory::createMany(10);
        BlogRedactorFactory::createMany(10);
        BlogFactory::createMany(20);
    }
}
