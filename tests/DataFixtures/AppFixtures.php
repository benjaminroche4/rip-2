<?php

namespace App\Tests\DataFixtures;

use App\Tests\Contact\Factory\ContactFactory;
use App\Tests\Newsletter\Factory\NewsletterFactory;
use App\Tests\PropertyEstimation\Factory\PropertyEstimationFactory;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * php bin/console doctrine:fixtures:load.
 */
class AppFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        ContactFactory::createMany(5);
        PropertyEstimationFactory::createMany(20);
        NewsletterFactory::createMany(20);
    }
}
