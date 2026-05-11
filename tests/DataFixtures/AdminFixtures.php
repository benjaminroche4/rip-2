<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures;

use App\Admin\Domain\HouseholdTypology;
use App\Admin\Domain\PersonRole;
use App\Admin\Domain\RequestLanguage;
use App\Admin\Entity\Document;
use App\Admin\Entity\DocumentRequest;
use App\Admin\Entity\PersonRequest;
use App\Auth\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Seed minimal mais utilisable pour le développement local. Charge :
 *  - 1 admin (admin@rip.test / password) — pour accéder au panel /admin
 *  - 1 utilisateur standard (user@rip.test / password) — pour vérifier les 403
 *  - ~8 modèles de documents bilingues (catalogue tools/documents)
 *  - 3 DocumentRequest avec foyers variés pour peupler le tableau
 *    "Demandes récentes"
 *
 * Lancement :  php bin/console doctrine:fixtures:load --env=dev
 * (un prompt confirme avant le DELETE FROM des tables concernées)
 *
 * Vit sous tests/DataFixtures/ (et non src/) pour ne pas être autoloadé en
 * prod où doctrine/doctrine-fixtures-bundle est require-dev. Sans ça,
 * `composer install --no-dev` casse au cache:clear faute du parent `Fixture`.
 */
final class AdminFixtures extends Fixture
{
    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $this->loadUsers($manager);
        $documents = $this->loadDocuments($manager);
        $this->loadDocumentRequests($manager, $documents);

        $manager->flush();
    }

    private function loadUsers(ObjectManager $manager): void
    {
        $admin = (new User())
            ->setEmail('admin@rip.test')
            ->setFirstName('Aurélie')
            ->setLastName('Dubois')
            ->setRoles(['ROLE_ADMIN'])
            ->setCreatedAt(new \DateTimeImmutable('-1 year'));
        $admin->setPassword($this->hasher->hashPassword($admin, 'password'));
        $manager->persist($admin);

        $user = (new User())
            ->setEmail('user@rip.test')
            ->setFirstName('Camille')
            ->setLastName('Martin')
            ->setRoles([])
            ->setCreatedAt(new \DateTimeImmutable('-3 months'));
        $user->setPassword($this->hasher->hashPassword($user, 'password'));
        $manager->persist($user);
    }

    /**
     * @return list<Document>
     */
    private function loadDocuments(ObjectManager $manager): array
    {
        $catalogue = [
            ['identite', 'Pièce d\'identité', 'ID card', 'Carte nationale d\'identité, passeport ou titre de séjour en cours de validité.', 'National ID, passport, or valid residence permit.', true],
            ['bulletins-salaire', 'Bulletins de salaire', 'Pay slips', 'Les 3 derniers bulletins de salaire.', 'Last 3 pay slips.', true],
            ['contrat-travail', 'Contrat de travail', 'Work contract', 'Contrat de travail en cours, signé et daté.', 'Current work contract, signed and dated.', false],
            ['avis-imposition', 'Avis d\'imposition', 'Tax notice', 'Dernier avis d\'imposition (3 pages).', 'Most recent tax notice (3 pages).', false],
            ['justificatif-domicile', 'Justificatif de domicile', 'Proof of address', 'Quittance de loyer ou facture de moins de 3 mois.', 'Rent receipt or utility bill less than 3 months old.', false],
            ['rib', 'RIB', 'Bank details', 'Relevé d\'identité bancaire au nom du locataire.', 'Bank details in the tenant\'s name.', false],
            ['k-bis', 'Extrait K-bis', 'K-bis extract', 'Extrait K-bis de moins de 3 mois pour les sociétés.', 'K-bis extract less than 3 months old for companies.', false],
            ['bilan-comptable', 'Bilan comptable', 'Annual accounts', 'Bilans comptables des 2 derniers exercices.', 'Annual accounts for the last 2 fiscal years.', false],
        ];

        $documents = [];
        foreach ($catalogue as [$slug, $nameFr, $nameEn, $descFr, $descEn, $pinned]) {
            $doc = (new Document())
                ->setSlug($slug)
                ->setNameFr($nameFr)
                ->setNameEn($nameEn)
                ->setDescriptionFr($descFr)
                ->setDescriptionEn($descEn)
                ->setPinned($pinned)
                ->setCreatedAt(new \DateTimeImmutable('-'.random_int(7, 180).' days'));
            $manager->persist($doc);
            $documents[] = $doc;
        }

        return $documents;
    }

    /**
     * @param list<Document> $documents
     */
    private function loadDocumentRequests(ObjectManager $manager, array $documents): void
    {
        if (\count($documents) < 4) {
            return;
        }

        $scenarios = [
            [
                'typology' => HouseholdTypology::TWO_TENANTS_TWO_GUARANTORS,
                'language' => RequestLanguage::FR,
                'createdAt' => new \DateTimeImmutable('-2 days'),
                'persons' => [
                    ['Jean', 'Dupont', PersonRole::TENANT],
                    ['Marie', 'Dupont', PersonRole::TENANT],
                    ['Pierre', 'Lefèvre', PersonRole::GUARANTOR],
                    ['Anne', 'Lefèvre', PersonRole::GUARANTOR],
                ],
            ],
            [
                'typology' => HouseholdTypology::ONE_TENANT_ONE_GUARANTOR,
                'language' => RequestLanguage::EN,
                'createdAt' => new \DateTimeImmutable('-1 week'),
                'persons' => [
                    ['John', 'Smith', PersonRole::TENANT],
                    ['Robert', 'Smith', PersonRole::GUARANTOR],
                ],
            ],
            [
                'typology' => HouseholdTypology::ONE_TENANT,
                'language' => RequestLanguage::FR,
                'createdAt' => new \DateTimeImmutable('-3 weeks'),
                'persons' => [
                    ['Sophie', 'Bernard', PersonRole::TENANT],
                ],
            ],
        ];

        foreach ($scenarios as $scenario) {
            $request = (new DocumentRequest())
                ->setTypology($scenario['typology'])
                ->setLanguage($scenario['language'])
                ->setDriveLink('https://drive.example.test/'.bin2hex(random_bytes(8)))
                ->setNote('Merci de transmettre vos pièces avant la fin du mois.')
                ->setCreatedAt($scenario['createdAt']);

            $i = 0;
            foreach ($scenario['persons'] as [$firstName, $lastName, $role]) {
                $person = (new PersonRequest())
                    ->setFirstName($firstName)
                    ->setLastName($lastName)
                    ->setRole($role)
                    ->setPosition($i++);
                // Each person gets the 3 pinned-most-common documents.
                foreach (\array_slice($documents, 0, 3) as $doc) {
                    $person->addDocument($doc);
                }
                $request->addPerson($person);
            }

            $manager->persist($request);
        }
    }
}
