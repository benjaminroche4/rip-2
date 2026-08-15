<?php

declare(strict_types=1);

namespace App\Tests\Dossier;

use App\Dossier\Domain\DossierDocumentType;
use App\Dossier\Entity\DossierDocument;
use App\Dossier\Entity\DossierDocumentFile;
use App\Dossier\Entity\DossierPerson;
use App\Dossier\Service\DossierDocumentNamer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DossierDocumentNamerTest extends KernelTestCase
{
    private DossierDocumentNamer $namer;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->namer = static::getContainer()->get(DossierDocumentNamer::class);
    }

    public function testItNamesAfterThePieceTypeAndPerson(): void
    {
        $document = $this->document();

        self::assertSame("Pièce d'identité - Jean Dupont.pdf", $this->namer->displayName($document, 'pdf'));
    }

    public function testItNamesInFrenchWhateverTheCurrentLocale(): void
    {
        $translator = static::getContainer()->get('translator');
        $previous = $translator->getLocale();
        $translator->setLocale('en');
        try {
            self::assertSame("Pièce d'identité - Jean Dupont.pdf", $this->namer->displayName($this->document(), 'pdf'));
        } finally {
            $translator->setLocale($previous);
        }
    }

    public function testItSuffixesThePositionWhenThePieceAlreadyHoldsFiles(): void
    {
        $document = $this->document();
        $document->addFile((new DossierDocumentFile())->setStoredName('a.pdf')->setOriginalName('a.pdf'));

        self::assertSame("Pièce d'identité - Jean Dupont (2).pdf", $this->namer->displayName($document, 'pdf'));
    }

    public function testItKeepsTheRealExtensionForImages(): void
    {
        self::assertStringEndsWith('.jpg', $this->namer->displayName($this->document(), 'jpg'));
    }

    public function testItFallsBackWithoutTypeOrPerson(): void
    {
        $document = (new DossierDocument())->setType(null);

        self::assertSame('Document.pdf', $this->namer->displayName($document, ''));
    }

    public function testItStripsFileSystemHostileCharacters(): void
    {
        $person = (new DossierPerson())->setFirstName('Jean/../')->setLastName('Du:pont');
        $document = (new DossierDocument())->setType(DossierDocumentType::Identity);
        $person->addDocument($document);

        $name = $this->namer->displayName($document, 'pdf');
        self::assertStringNotContainsString('/', $name);
        self::assertStringNotContainsString(':', $name);
        self::assertStringEndsWith('.pdf', $name);
    }

    private function document(): DossierDocument
    {
        $person = (new DossierPerson())->setFirstName('Jean')->setLastName('Dupont');
        $document = (new DossierDocument())->setType(DossierDocumentType::Identity);
        $person->addDocument($document);

        return $document;
    }
}
