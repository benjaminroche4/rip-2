<?php

declare(strict_types=1);

namespace App\Tests\Admin\Service;

use App\Admin\Domain\HouseholdTypology;
use App\Admin\Domain\PersonRole;
use App\Admin\Domain\RequestLanguage;
use App\Admin\Entity\Document;
use App\Admin\Entity\DocumentRequest;
use App\Admin\Entity\PersonRequest;
use App\Admin\Service\DocumentRequestPdfRenderer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Renders a real DocumentRequest through Dompdf and asserts the output is a
 * valid PDF (header byte signature). Locale-aware: both FR and EN must
 * produce a non-empty PDF — proves the bilingual template branches.
 */
final class DocumentRequestPdfRendererTest extends KernelTestCase
{
    public function testRendersValidPdfInFrench(): void
    {
        $pdf = $this->buildRenderer()->render($this->buildRequest(RequestLanguage::FR));

        self::assertNotEmpty($pdf);
        // Every PDF starts with the "%PDF-" magic bytes.
        self::assertStringStartsWith('%PDF-', $pdf);
    }

    public function testRendersValidPdfInEnglish(): void
    {
        $pdf = $this->buildRenderer()->render($this->buildRequest(RequestLanguage::EN));

        self::assertNotEmpty($pdf);
        self::assertStringStartsWith('%PDF-', $pdf);
    }

    public function testRendersWhenNoteIsSet(): void
    {
        $request = $this->buildRequest(RequestLanguage::FR);
        $request->setNote("Bonjour,\nMerci de transmettre vos pièces avant vendredi.");

        $pdf = $this->buildRenderer()->render($request);

        self::assertStringStartsWith('%PDF-', $pdf);
        // PDF binary is compressed/encoded; we can't easily grep for content,
        // but a non-empty document with a header is enough to know the
        // optional note branch didn't blow up during render.
        self::assertGreaterThan(1000, \strlen($pdf));
    }

    public function testRendersWhenNoteIsAbsent(): void
    {
        $request = $this->buildRequest(RequestLanguage::FR);
        // note left null — the {% if request.note %} branch must skip cleanly.
        self::assertNull($request->getNote());

        $pdf = $this->buildRenderer()->render($request);

        self::assertStringStartsWith('%PDF-', $pdf);
    }

    public function testFilenameContainsDateAndId(): void
    {
        $request = $this->buildRequest(RequestLanguage::FR);
        $request->setCreatedAt(new \DateTimeImmutable('2026-05-11'));
        // Force an id without persisting — set via reflection.
        $idProp = new \ReflectionProperty(DocumentRequest::class, 'id');
        $idProp->setValue($request, 42);

        $filename = $this->buildRenderer()->filename($request);

        self::assertSame('demande-documents-2026-05-11-42.pdf', $filename);
    }

    private function buildRenderer(): DocumentRequestPdfRenderer
    {
        self::bootKernel();

        return self::getContainer()->get(DocumentRequestPdfRenderer::class);
    }

    private function buildRequest(RequestLanguage $language): DocumentRequest
    {
        $doc = (new Document())
            ->setNameFr('Pièce d\'identité')
            ->setNameEn('Government ID')
            ->setDescriptionFr('Carte nationale d\'identité ou passeport.')
            ->setDescriptionEn('National ID card or passport.')
            ->setSlug('piece-identite')
            ->setCreatedAt(new \DateTimeImmutable());

        $person = (new PersonRequest())
            ->setRole(PersonRole::TENANT)
            ->setFirstName('Jean')
            ->setLastName('Dupont');
        $person->addDocument($doc);

        $request = (new DocumentRequest())
            ->setTypology(HouseholdTypology::ONE_TENANT)
            ->setDriveLink('https://drive.example.com/upload/abc')
            ->setLanguage($language)
            ->setCreatedAt(new \DateTimeImmutable());
        $request->addPerson($person);

        return $request;
    }
}
