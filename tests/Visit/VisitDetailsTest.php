<?php

declare(strict_types=1);

namespace App\Tests\Visit;

use App\Auth\Entity\User;
use App\Dossier\Entity\Dossier;
use App\Visit\Entity\Visit;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * Read-only detail card of the visit page: the "..." menu carries the two
 * mutations (Modifier -> dedicated edit page, Supprimer -> confirm modal),
 * the card itself only displays the stored values. The inline editing
 * (padlock + autosave) is gone.
 */
final class VisitDetailsTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private const PREFIX = 'test_admin_prefix_1234567890abcdef';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.Visit::class)->execute();
        $this->em->createQuery('DELETE FROM '.Dossier::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@visit-details-test.local')->execute();
        $this->loginAsAdmin();
    }

    protected function tearDown(): void
    {
        $this->em->createQuery('DELETE FROM '.Visit::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@visit-details-test.local')->execute();
        parent::tearDown();
    }

    public function testTheCardIsReadOnly(): void
    {
        $visit = $this->persistVisit();
        $visit->setNote('Code 4812, gardien au rez-de-chaussée')
            ->setListingUrl('https://www.seloger.com/annonce-123')
            ->setClientPresent(true);
        $this->em->flush();

        $rendered = $this->render($visit);

        // Valeurs affichées en lecture.
        self::assertStringContainsString('Code 4812, gardien au rez-de-chaussée', $rendered);
        self::assertStringContainsString('data-testid="visit-show-listing"', $rendered);
        self::assertStringContainsString('data-testid="visit-show-client-present"', $rendered);
        self::assertStringContainsString('Famille Martin', $rendered);

        // Plus aucun vestige de l'édition en place : ni cadenas, ni inputs.
        self::assertStringNotContainsString('data-testid="visit-details-lock"', $rendered);
        self::assertStringNotContainsString('data-testid="visit-details-address"', $rendered);
        self::assertStringNotContainsString('data-model=', $rendered);
        self::assertStringNotContainsString('data-live-action-param="save"', $rendered);
        self::assertStringNotContainsString('data-testid="visit-details-note"', $rendered);
    }

    public function testTheMenuLinksToTheEditPage(): void
    {
        $visit = $this->persistVisit();

        $rendered = $this->render($visit);

        self::assertStringContainsString('data-testid="visit-details-menu"', $rendered);
        // L'entrée Modifier pointe vers la page d'édition dédiée.
        $edit = $this->attribute($rendered, 'visit-details-edit', 'href');
        self::assertStringContainsString(self::PREFIX.'/admin', $edit);
        self::assertStringContainsString((string) $visit->getReference(), $edit);
        self::assertMatchesRegularExpression('~/(modifier|edit)$~', $edit);
    }

    public function testTheMenuCarriesTheDeleteConfirmModal(): void
    {
        $visit = $this->persistVisit();

        $rendered = $this->render($visit);

        // Le déclencheur Supprimer vit dans le menu "..." et ouvre la modale
        // de confirmation (AlertDialog), dont le POST cible la route delete.
        self::assertStringContainsString('data-testid="visit-show-delete-trigger"', $rendered);
        // Le menu ne se referme PAS à l'ouverture : la <dialog> vit dans le
        // sous-arbre du <details>, un details fermé la rendrait invisible.
        self::assertStringContainsString('data-action="click->alert-dialog#open"', $rendered);
        self::assertStringNotContainsString('alert-dialog#open details-dropdown#close', $rendered);
        self::assertStringContainsString('data-testid="visit-show-delete"', $rendered);
        self::assertMatchesRegularExpression('~/(supprimer|delete)"~', $rendered);
        // La card Actions latérale ne garde que les statuts.
        $aside = (string) strstr($rendered, 'data-testid="visit-actions-card"');
        self::assertStringContainsString('data-testid="visit-status-actions"', $aside);
        self::assertStringNotContainsString('visit-show-delete-trigger', $aside, 'Delete left the side Actions card.');
    }

    public function testStatusChipsStayInTheSideActionsCard(): void
    {
        $visit = $this->persistVisit();

        $rendered = $this->render($visit);

        foreach (['planned', 'done', 'cancelled'] as $status) {
            self::assertStringContainsString('data-testid="visit-status-'.$status.'"', $rendered);
        }
    }

    private function render(Visit $visit): string
    {
        return (string) $this->renderTwigComponent('Visit:VisitDetails', [
            'visitId' => (int) $visit->getId(),
            'adminPrefix' => self::PREFIX,
        ]);
    }

    /** Valeur d'un attribut sur l'élément portant le data-testid donné. */
    private function attribute(string $html, string $testid, string $attribute): string
    {
        preg_match('~<[^>]*data-testid="'.preg_quote($testid, '~').'"[^>]*>~', $html, $tag);
        self::assertNotSame([], $tag, 'Element '.$testid.' not found.');
        preg_match('~'.$attribute.'="([^"]*)"~', $tag[0], $value);

        return html_entity_decode($value[1] ?? '');
    }

    private function persistVisit(): Visit
    {
        $dossier = (new Dossier())
            ->setName('Famille Martin')
            ->setReference('DS-'.random_int(100000, 999999))
            ->setPairingCode(substr(strtoupper(bin2hex(random_bytes(4))), 0, 6))
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($dossier);

        $visit = (new Visit())
            ->setReference('VS-'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT))
            ->setDossier($dossier)
            ->setScheduledAt(new \DateTimeImmutable('+2 days 10:30'))
            ->setAddress('12 rue de la Roquette, 75011 Paris')
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($visit);
        $this->em->flush();

        return $visit;
    }

    private function loginAsAdmin(): void
    {
        $admin = (new User())
            ->setEmail(bin2hex(random_bytes(4)).'@visit-details-test.local')
            ->setFirstName('First')->setLastName('Last')
            ->setRoles(['ROLE_ADMIN'])->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($admin);
        $this->em->flush();
        $token = new UsernamePasswordToken($admin, 'main', $admin->getRoles());
        self::getContainer()->get('security.token_storage')->setToken($token);
    }
}
