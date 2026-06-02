<?php

declare(strict_types=1);

namespace App\Tests\Admin\Twig\Components;

use App\Admin\Domain\DocumentCategory;
use App\Admin\Domain\HouseholdTypology;
use App\Admin\Domain\PersonRole;
use App\Admin\Domain\RequestLanguage;
use App\Admin\Entity\Document;
use App\Admin\Entity\DocumentRequest;
use App\Admin\Entity\PersonRequest;
use App\Admin\Repository\DocumentRequestRepository;
use App\Auth\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * Component-level checks for Admin:DocumentRequestForm:
 *  - mount requires ROLE_ADMIN
 *  - mount pre-fills one PersonRequest (so the admin starts on a usable form)
 *  - the form renders all the structural test ids the UI relies on
 */
final class DocumentRequestFormComponentTest extends KernelTestCase
{
    use InteractsWithLiveComponents;
    use InteractsWithTwigComponents;

    private const ADMIN_PREFIX = 'test_admin_prefix_1234567890abcdef';

    private EntityManagerInterface $em;
    private DocumentRequestRepository $requestRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');
        $this->requestRepository = $container->get(DocumentRequestRepository::class);

        $this->em->createQuery('DELETE FROM '.DocumentRequest::class)->execute();
        $this->em->createQuery('DELETE FROM '.Document::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class)->execute();
    }

    public function testMountRequiresAdmin(): void
    {
        $this->seedUser('user@example.com');
        $this->loginAs('user@example.com');

        $this->expectException(AccessDeniedException::class);
        $this->mountTwigComponent('Admin:DocumentRequestForm', ['adminPrefix' => 'test_admin_prefix_1234567890abcdef']);
    }

    public function testMountSeedsOnePerson(): void
    {
        $this->seedUser('admin@example.com', ['ROLE_ADMIN']);
        $this->loginAs('admin@example.com');

        $component = $this->mountTwigComponent('Admin:DocumentRequestForm', ['adminPrefix' => 'test_admin_prefix_1234567890abcdef']);

        self::assertNotNull($component->request);
        self::assertCount(1, $component->request->getPersons(), 'A blank PersonRequest should be pre-filled on mount.');
    }

    public function testRenderedFormExposesAllSections(): void
    {
        $this->seedUser('admin@example.com', ['ROLE_ADMIN']);
        $this->loginAs('admin@example.com');

        $html = (string) $this->renderTwigComponent('Admin:DocumentRequestForm', ['adminPrefix' => 'test_admin_prefix_1234567890abcdef']);

        self::assertStringContainsString('data-testid="document-request-form"', $html);
        self::assertStringContainsString('data-testid="document-request-person"', $html);
        self::assertStringContainsString('data-testid="document-request-add-person"', $html);
        self::assertStringContainsString('data-testid="document-request-typology"', $html);
        self::assertStringContainsString('data-testid="document-request-drive"', $html);
        self::assertStringContainsString('data-testid="document-request-language"', $html);
        self::assertStringContainsString('data-testid="document-request-submit"', $html);
        // Save + Download are two distinct buttons; the unsaved-changes guard
        // modal is part of the form markup.
        self::assertStringContainsString('data-testid="document-request-download"', $html);
        self::assertStringContainsString('data-testid="unsaved-changes-dialog"', $html);
        // The form root wires both the download-trigger and unsaved-changes
        // Stimulus controllers (LiveComponent prepends its own `live` controller
        // to the attribute, so we match each token rather than the full value).
        self::assertMatchesRegularExpression('/data-controller="[^"]*\bdownload-trigger\b/', $html);
        self::assertMatchesRegularExpression('/data-controller="[^"]*\bunsaved-changes\b/', $html);
        // Both modal choices return to the documents hub.
        self::assertStringContainsString('data-unsaved-changes-redirect-url-value="', $html);
        self::assertStringContainsString('/admin/outils/documents"', $html);
    }

    public function testEditModeMountPrefillsFormFromExistingRequest(): void
    {
        $this->seedUser('admin@example.com', ['ROLE_ADMIN']);
        $this->loginAs('admin@example.com');
        $doc = $this->seedDocument();
        $request = $this->seedRequest($doc, [['Jean', 'Dupont'], ['Marie', 'Martin']]);

        $component = $this->mountTwigComponent('Admin:DocumentRequestForm', [
            'adminPrefix' => self::ADMIN_PREFIX,
            'editId' => $request->getId(),
        ]);

        self::assertSame($request->getId(), $component->editId);
        self::assertNotNull($component->request);
        self::assertCount(2, $component->request->getPersons(), 'Both saved persons should be pre-filled in edit mode.');
        // Loaded as an id-less draft so live edits round-trip across renders.
        self::assertNull($component->request->getId());
    }

    public function testSaveUpdatesExistingRequestInPlace(): void
    {
        $admin = $this->seedUser('admin@example.com', ['ROLE_ADMIN']);
        $doc = $this->seedDocument();
        $request = $this->seedRequest($doc, [['Jean', 'Dupont']]);
        $originalId = $request->getId();

        $component = $this->createLiveComponent('Admin:DocumentRequestForm', [
            'adminPrefix' => self::ADMIN_PREFIX,
            'editId' => $originalId,
        ])->actingAs($admin);

        $formName = $component->component()->getFormName();
        $component->submitForm([
            $formName => [
                'typology' => 'two_tenants',
                'note' => 'Updated note',
                'driveLink' => 'https://drive.example.test/updated',
                'language' => 'en',
                'persons' => [
                    ['role' => 'tenant', 'firstName' => 'Updated', 'lastName' => 'Person', 'documents' => [(string) $doc->getId()]],
                ],
            ],
        ], 'save');

        $this->em->clear();
        // Still exactly one row — save() updates in place, it does not insert.
        self::assertCount(1, $this->requestRepository->findAll());
        $updated = $this->requestRepository->find($originalId);
        self::assertNotNull($updated);
        self::assertSame('https://drive.example.test/updated', $updated->getDriveLink());
        self::assertSame(HouseholdTypology::TWO_TENANTS, $updated->getTypology());
        self::assertSame(RequestLanguage::EN, $updated->getLanguage());
        self::assertSame('Updated note', $updated->getNote());
        self::assertCount(1, $updated->getPersons());
        self::assertSame('Updated', $updated->getPersons()->toArray()[0]->getFirstName());

        // Save persists only — it signals the client to clear the dirty flag,
        // it does not trigger a download.
        $this->assertComponentDispatchBrowserEvent($component, 'document-request:saved');
    }

    public function testSaveInCreateModeInsertsANewRow(): void
    {
        $admin = $this->seedUser('admin@example.com', ['ROLE_ADMIN']);
        $doc = $this->seedDocument();

        $component = $this->createLiveComponent('Admin:DocumentRequestForm', [
            'adminPrefix' => self::ADMIN_PREFIX,
        ])->actingAs($admin);

        $formName = $component->component()->getFormName();
        $component->submitForm([
            $formName => [
                'typology' => 'one_tenant',
                'note' => '',
                'driveLink' => 'https://drive.example.test/new',
                'language' => 'fr',
                'persons' => [
                    ['role' => 'tenant', 'firstName' => 'Alice', 'lastName' => 'Durand', 'documents' => [(string) $doc->getId()]],
                ],
            ],
        ], 'save');

        self::assertCount(1, $this->requestRepository->findAll());
        $this->assertComponentDispatchBrowserEvent($component, 'document-request:saved');
    }

    public function testDownloadUpsertsAndDispatchesTheDownloadEvent(): void
    {
        $admin = $this->seedUser('admin@example.com', ['ROLE_ADMIN']);
        $doc = $this->seedDocument();

        $component = $this->createLiveComponent('Admin:DocumentRequestForm', [
            'adminPrefix' => self::ADMIN_PREFIX,
        ])->actingAs($admin);

        $formName = $component->component()->getFormName();
        $component->submitForm([
            $formName => [
                'typology' => 'one_tenant',
                'note' => '',
                'driveLink' => 'https://drive.example.test/dl',
                'language' => 'fr',
                'persons' => [
                    ['role' => 'tenant', 'firstName' => 'Bob', 'lastName' => 'Leroy', 'documents' => [(string) $doc->getId()]],
                ],
            ],
        ], 'download');

        // Download persists too (a PDF can only be served from a saved row).
        self::assertCount(1, $this->requestRepository->findAll());
        $this->assertComponentDispatchBrowserEvent($component, 'document-request:download');
    }

    private function seedDocument(): Document
    {
        $doc = (new Document())
            ->setNameFr('Pièce identité')
            ->setNameEn('ID')
            ->setSlug('piece-identite-'.bin2hex(random_bytes(3)))
            ->setCategory(DocumentCategory::IDENTITY)
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($doc);
        $this->em->flush();

        return $doc;
    }

    /**
     * @param list<array{0:string,1:string}> $persons first name + last name pairs
     */
    private function seedRequest(Document $doc, array $persons): DocumentRequest
    {
        $request = (new DocumentRequest())
            ->setTypology(HouseholdTypology::ONE_TENANT)
            ->setLanguage(RequestLanguage::FR)
            ->setDriveLink('https://drive.example.test/original')
            ->setNote('Original')
            ->setCreatedAt(new \DateTimeImmutable());

        foreach ($persons as [$first, $last]) {
            $person = (new PersonRequest())
                ->setRole(PersonRole::TENANT)
                ->setFirstName($first)
                ->setLastName($last);
            $person->addDocument($doc);
            $request->addPerson($person);
        }

        $this->em->persist($request);
        $this->em->flush();

        return $request;
    }

    /** @param list<string> $roles */
    private function seedUser(string $email, array $roles = []): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setFirstName('First')
            ->setLastName('Last')
            ->setRoles($roles)
            ->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)
            ->setVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function loginAs(string $email): void
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user);

        $token = new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken($user, 'main', $user->getRoles());
        self::getContainer()->get('security.token_storage')->setToken($token);
    }
}
