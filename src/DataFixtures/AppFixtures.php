<?php

namespace App\DataFixtures;

use App\Contact\Factory\ContactFactory;
use App\Newsletter\Factory\NewsletterFactory;
use App\Factory\PropertyEstimationFactory;
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

        PropertyEstimationFactory::createMany(20);
        NewsletterFactory::createMany(20);
    }
}
