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
use App\Shared\Pdf\PdfFormat;
use App\Shared\Pdf\PdfOptions;
use App\Shared\Pdf\PdfOrientation;
use App\Shared\Pdf\PdfRenderer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Verifies that the admin-facing PDF service:
 *   1. renders the bilingual Twig template (locale branches FR and EN)
 *   2. forwards the resulting HTML to the generic PdfRenderer with the
 *      expected PdfOptions (A4 portrait, custom margins)
 *   3. composes a deterministic filename
 *
 * We inject a recording double of PdfRenderer so the suite never reaches
 * DocRaptor — keeps the test deterministic, free, and fast.
 */
final class DocumentRequestPdfRendererTest extends KernelTestCase
{
    public function testRendersBilingualHtmlInFrench(): void
    {
        $recorder = new RecordingPdfRenderer();
        $service = $this->buildService($recorder);

        $bytes = $service->render($this->buildRequest(RequestLanguage::FR));

        self::assertNotEmpty($bytes);
        self::assertSame('%PDF-stub', $bytes);
        self::assertNotNull($recorder->lastHtml);
        // FR branch of the template uses these exact strings — proves the
        // locale switch reached the template, not just the service.
        self::assertStringContainsString('Demande de pièces', $recorder->lastHtml);
        self::assertStringContainsString('Composition du foyer', $recorder->lastHtml);
    }

    public function testRendersBilingualHtmlInEnglish(): void
    {
        $recorder = new RecordingPdfRenderer();
        $service = $this->buildService($recorder);

        $service->render($this->buildRequest(RequestLanguage::EN));

        self::assertNotNull($recorder->lastHtml);
        self::assertStringContainsString('Document request', $recorder->lastHtml);
        self::assertStringContainsString('Household composition', $recorder->lastHtml);
    }

    public function testForwardsExpectedPdfOptions(): void
    {
        $recorder = new RecordingPdfRenderer();
        $service = $this->buildService($recorder);

        $service->render($this->buildRequest(RequestLanguage::FR));

        self::assertInstanceOf(PdfOptions::class, $recorder->lastOptions);
        self::assertSame(PdfFormat::A4, $recorder->lastOptions->format);
        self::assertSame(PdfOrientation::Portrait, $recorder->lastOptions->orientation);
        self::assertSame('18mm', $recorder->lastOptions->marginTop);
        self::assertSame('16mm', $recorder->lastOptions->marginRight);
        self::assertSame('20mm', $recorder->lastOptions->marginBottom);
        self::assertSame('16mm', $recorder->lastOptions->marginLeft);
    }

    public function testRenderingDoesNotThrowWhenNoteIsSet(): void
    {
        $recorder = new RecordingPdfRenderer();
        $service = $this->buildService($recorder);

        $request = $this->buildRequest(RequestLanguage::FR);
        $request->setNote("Bonjour,\nMerci de transmettre vos pièces avant vendredi.");

        // Reaching this line without an exception is the assertion: the
        // optional note branch in the template must not blow up. (The
        // template currently doesn't surface the note in the PDF — it's
        // a backend-only field — so we only assert smoke behaviour here.)
        $service->render($request);

        self::assertNotEmpty($recorder->lastHtml);
    }

    public function testNoteAbsentRenderSkipsCleanly(): void
    {
        $recorder = new RecordingPdfRenderer();
        $service = $this->buildService($recorder);

        $request = $this->buildRequest(RequestLanguage::FR);
        self::assertNull($request->getNote());

        // Reaching this line without an exception is the assertion: the
        // {% if request.note %} branch in the template must skip cleanly.
        $service->render($request);

        self::assertNotEmpty($recorder->lastHtml);
    }

    public function testFilenameContainsDateAndId(): void
    {
        $service = $this->buildService(new RecordingPdfRenderer());
        $request = $this->buildRequest(RequestLanguage::FR);
        $request->setCreatedAt(new \DateTimeImmutable('2026-05-11'));
        // Force an id without persisting — set via reflection.
        $idProp = new \ReflectionProperty(DocumentRequest::class, 'id');
        $idProp->setValue($request, 42);

        $filename = $service->filename($request);

        self::assertSame('demande-documents-2026-05-11-42.pdf', $filename);
    }

    private function buildService(PdfRenderer $pdfRenderer): DocumentRequestPdfRenderer
    {
        self::bootKernel();
        $container = self::getContainer();

        // Swap the live PdfRenderer (DocRaptor) for the recorder so the
        // test never reaches the network. The Admin service is fetched
        // through the container so Twig + translator are wired exactly
        // like in production.
        $container->set(PdfRenderer::class, $pdfRenderer);

        return $container->get(DocumentRequestPdfRenderer::class);
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

/**
 * Captures the (html, options) pair the service handed to the renderer
 * so the test can assert what was sent without spinning up DocRaptor.
 *
 * @internal
 */
final class RecordingPdfRenderer implements PdfRenderer
{
    public ?string $lastHtml = null;
    public ?PdfOptions $lastOptions = null;

    public function render(string $html, ?PdfOptions $options = null): string
    {
        $this->lastHtml = $html;
        $this->lastOptions = $options;

        return '%PDF-stub';
    }
}
