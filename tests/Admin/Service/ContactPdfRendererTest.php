<?php

declare(strict_types=1);

namespace App\Tests\Admin\Service;

use App\Admin\Service\ContactPdfRenderer;
use App\Contact\Domain\ContactListItem;
use App\Contact\Domain\ContactStatus;
use App\Shared\Pdf\PdfFormat;
use App\Shared\Pdf\PdfOptions;
use App\Shared\Pdf\PdfOrientation;
use App\Shared\Pdf\PdfRenderer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Same harness as DocumentRequestPdfRendererTest: a recording double of
 * PdfRenderer so the suite never reaches DocRaptor.
 */
final class ContactPdfRendererTest extends KernelTestCase
{
    public function testRendersFrenchHtmlWithContactData(): void
    {
        $recorder = new RecordingContactPdfRenderer();
        $service = $this->buildService($recorder);

        $bytes = $service->render($this->buildContact(lang: 'fr'));

        self::assertSame('%PDF-stub', $bytes);
        self::assertNotNull($recorder->lastHtml);
        self::assertStringContainsString('Demande de contact', $recorder->lastHtml);
        self::assertStringContainsString('Jane Doe', $recorder->lastHtml);
        self::assertStringContainsString('CT-424242', $recorder->lastHtml);
        self::assertStringContainsString('Bonjour, je cherche un appartement.', $recorder->lastHtml);
        self::assertStringContainsString('+33 6 12 34 56 78', $recorder->lastHtml);
    }

    public function testRendersEnglishBranch(): void
    {
        $recorder = new RecordingContactPdfRenderer();
        $service = $this->buildService($recorder);

        $service->render($this->buildContact(lang: 'en'));

        self::assertNotNull($recorder->lastHtml);
        self::assertStringContainsString('Contact request', $recorder->lastHtml);
        self::assertStringContainsString('Received on', $recorder->lastHtml);
    }

    public function testExportsFollowUpButKeepsInternalFieldsOut(): void
    {
        $recorder = new RecordingContactPdfRenderer();
        $service = $this->buildService($recorder);

        $service->render($this->buildContact(lang: 'fr'));

        self::assertNotNull($recorder->lastHtml);
        // Partner-safe follow-up info is included...
        self::assertStringContainsString('Julien Moreau', $recorder->lastHtml);
        // The internal status never shows on a partner-facing document.
        self::assertStringNotContainsString('À traiter', $recorder->lastHtml, 'The status must stay internal.');
        // ...but internal-only data never leaves the admin.
        self::assertStringNotContainsString('127.0.0.1', $recorder->lastHtml, 'The IP must stay internal.');
        self::assertStringNotContainsString('Note interne secrète', $recorder->lastHtml, 'The lead note must stay internal.');
    }

    public function testForwardsExpectedPdfOptions(): void
    {
        $recorder = new RecordingContactPdfRenderer();
        $service = $this->buildService($recorder);

        $service->render($this->buildContact(lang: 'fr'));

        self::assertInstanceOf(PdfOptions::class, $recorder->lastOptions);
        self::assertSame(PdfFormat::A4, $recorder->lastOptions->format);
        self::assertSame(PdfOrientation::Portrait, $recorder->lastOptions->orientation);
    }

    public function testFilenameUsesTheReference(): void
    {
        $service = $this->buildService(new RecordingContactPdfRenderer());

        self::assertSame('demande-contact-CT-424242.pdf', $service->filename($this->buildContact(lang: 'fr')));
    }

    public function testRendersAssigneeAreaLabelsAndDistrictMap(): void
    {
        $recorder = new RecordingContactPdfRenderer();
        $service = $this->buildService($recorder);

        $contact = new ContactListItem(
            id: 2,
            firstName: 'jane',
            lastName: 'doe',
            email: 'jane@example.com',
            phoneNumber: null,
            company: null,
            helpType: 'contact.contactForm.helpType.choice.1',
            message: null,
            createdAt: new \DateTimeImmutable('2026-03-18 14:00'),
            lang: 'fr',
            reference: 'CT-424243',
            status: ContactStatus::InProgress,
            statusChangedBy: null,
            statusChangedByAvatar: null,
            projectAreas: '11e,92',
            assigneeId: 7,
            assigneeName: 'julien moreau',
        );
        $service->render($contact);
        $html = (string) $recorder->lastHtml;

        self::assertStringContainsString('Suivi par', $html);
        self::assertStringContainsString('Julien Moreau', $html);
        self::assertStringContainsString('11e, Hauts-de-Seine (92)', $html, 'Area codes render as labels.');
        self::assertStringContainsString('<svg', $html, 'The district map is embedded.');
        self::assertStringContainsString('fill="#71172e"', $html, 'Selected districts are highlighted.');
    }

    public function testNoDistrictMapWithoutKnownAreas(): void
    {
        $recorder = new RecordingContactPdfRenderer();
        $service = $this->buildService($recorder);

        $service->render($this->buildContact('fr'));

        // Row icons are inline SVGs too: assert on the map section itself.
        self::assertStringNotContainsString('Quartiers souhaités', (string) $recorder->lastHtml);
        self::assertStringNotContainsString('viewBox="0 0 720', (string) $recorder->lastHtml);
    }

    private function buildService(PdfRenderer $pdfRenderer): ContactPdfRenderer
    {
        self::bootKernel();
        $container = self::getContainer();
        $container->set(PdfRenderer::class, $pdfRenderer);

        return $container->get(ContactPdfRenderer::class);
    }

    private function buildContact(string $lang): ContactListItem
    {
        return new ContactListItem(
            id: 1,
            firstName: 'jane',
            lastName: 'doe',
            email: 'jane@example.com',
            phoneNumber: '+33612345678',
            company: 'Acme Corp',
            helpType: 'contact.contactForm.helpType.choice.1',
            message: 'Bonjour, je cherche un appartement.',
            createdAt: new \DateTimeImmutable('2026-03-18 14:00'),
            lang: $lang,
            reference: 'CT-424242',
            status: ContactStatus::New,
            statusChangedBy: 'Julien Moreau',
            statusChangedByAvatar: null,
            ip: '127.0.0.1',
            statusChangedAt: new \DateTimeImmutable('2026-03-18 15:00'),
            firstTreatedAt: new \DateTimeImmutable('2026-03-18 14:30'),
            leadRating: 2,
            leadNote: 'Note interne secrète',
            offer: 'accompagne',
        );
    }
}

/**
 * @internal
 */
final class RecordingContactPdfRenderer implements PdfRenderer
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
