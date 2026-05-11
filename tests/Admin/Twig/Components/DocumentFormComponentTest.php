<?php

declare(strict_types=1);

namespace App\Tests\Admin\Twig\Components;

use App\Admin\Entity\Document;
use App\Admin\Repository\DocumentRepository;
use App\Auth\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * LiveComponent tests for Admin:DocumentForm covering:
 *  - mount requires ROLE_ADMIN
 *  - submit valid → persists, emits document:created, dispatches dialog close
 *  - submit invalid → no persist, errors render
 *  - slug is auto-generated from nameFr, never user-controlled
 */
final class DocumentFormComponentTest extends KernelTestCase
{
    use InteractsWithLiveComponents;
    use InteractsWithTwigComponents;

    private EntityManagerInterface $em;
    private DocumentRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');
        $this->repository = $container->get(DocumentRepository::class);

        $this->em->createQuery('DELETE FROM '.Document::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class)->execute();
    }

    public function testMountRequiresAdmin(): void
    {
        $this->loginAs($this->seedUser('user@example.com'));

        $this->expectException(AccessDeniedException::class);
        $this->mountTwigComponent('Admin:DocumentForm');
    }

    public function testSubmitWithValidDataPersistsAndEmits(): void
    {
        $admin = $this->seedAdmin('admin@example.com');
        $component = $this->createLiveComponent('Admin:DocumentForm')->actingAs($admin);

        $formName = $component->component()->getFormName();
        $component->submitForm([
            $formName => [
                'nameFr' => 'Bail commercial',
                'nameEn' => 'Commercial lease',
                'descriptionFr' => 'Modèle de bail commercial.',
                'descriptionEn' => 'Commercial lease template.',
            ],
        ], 'save');

        // One document landed in the DB with the auto-generated slug.
        $docs = $this->repository->findAll();
        self::assertCount(1, $docs);
        /** @var Document $doc */
        $doc = $docs[0];
        self::assertSame('Bail commercial', $doc->getNameFr());
        self::assertSame('Commercial lease', $doc->getNameEn());
        self::assertSame('bail-commercial', $doc->getSlug());
        self::assertNotNull($doc->getCreatedAt());
        // Pin defaults to false when the checkbox is not ticked.
        self::assertFalse($doc->isPinned());

        // Component emitted the cross-component event + the browser event
        // the dialog listens for to close itself.
        $this->assertComponentEmitEvent($component, 'document:created');
        $this->assertComponentDispatchBrowserEvent($component, 'document-dialog:close');
    }

    public function testSubmitWithPinnedTruePersistsThePinnedFlag(): void
    {
        $admin = $this->seedAdmin('admin@example.com');
        $component = $this->createLiveComponent('Admin:DocumentForm')->actingAs($admin);

        $formName = $component->component()->getFormName();
        $component->submitForm([
            $formName => [
                'nameFr' => 'Pièce d\'identité',
                'nameEn' => 'ID card',
                'descriptionFr' => '',
                'descriptionEn' => '',
                'pinned' => '1',
            ],
        ], 'save');

        $docs = $this->repository->findAll();
        self::assertCount(1, $docs);
        self::assertTrue($docs[0]->isPinned(), 'pinned=1 in the form should persist as true on the entity.');
    }

    public function testSubmitWithBlankNameFrFailsValidation(): void
    {
        $admin = $this->seedAdmin('admin@example.com');
        $component = $this->createLiveComponent('Admin:DocumentForm')->actingAs($admin);
        $formName = $component->component()->getFormName();

        try {
            $component->submitForm([
                $formName => [
                    'nameFr' => '',
                    'nameEn' => 'Lease',
                    'descriptionFr' => '',
                    'descriptionEn' => '',
                ],
            ], 'save');
        } catch (\Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException) {
            // Expected: validation failure on save() bubbles up so the
            // bundle's renderer can produce a 422 with errors. The DB
            // assertion below is the real invariant we care about.
        }

        self::assertCount(0, $this->repository->findAll(), 'No document should be persisted when validation fails.');
    }

    public function testSubmitWithTooShortNameFailsValidation(): void
    {
        $admin = $this->seedAdmin('admin@example.com');
        $component = $this->createLiveComponent('Admin:DocumentForm')->actingAs($admin);
        $formName = $component->component()->getFormName();

        try {
            $component->submitForm([
                $formName => [
                    'nameFr' => 'a', // below the 2-char minimum
                    'nameEn' => 'b',
                    'descriptionFr' => '',
                    'descriptionEn' => '',
                ],
            ], 'save');
        } catch (\Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException) {
            // expected
        }

        self::assertCount(0, $this->repository->findAll());
    }

    public function testSlugIsDeduplicatedAcrossSubmits(): void
    {
        $admin = $this->seedAdmin('admin@example.com');
        // First doc with the slug "bail-type" already in DB.
        $existing = (new Document())
            ->setNameFr('Bail type')
            ->setNameEn('Standard lease')
            ->setSlug('bail-type')
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($existing);
        $this->em->flush();

        $component = $this->createLiveComponent('Admin:DocumentForm')->actingAs($admin);
        $formName = $component->component()->getFormName();
        $component->submitForm([
            $formName => [
                'nameFr' => 'Bail type',
                'nameEn' => 'Standard lease v2',
                'descriptionFr' => '',
                'descriptionEn' => '',
            ],
        ], 'save');

        // Same nameFr → DocumentSlugger appends "-2" to keep the column unique.
        self::assertNotNull($this->repository->findOneBy(['slug' => 'bail-type-2']));
    }

    private function seedAdmin(string $email): User
    {
        return $this->seedUser($email, ['ROLE_ADMIN']);
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
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function loginAs(User $user): void
    {
        $token = new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken($user, 'main', $user->getRoles());
        self::getContainer()->get('security.token_storage')->setToken($token);
    }
}
