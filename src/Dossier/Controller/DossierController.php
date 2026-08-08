<?php

declare(strict_types=1);

namespace App\Dossier\Controller;

use App\Contact\Entity\Contact;
use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierDocumentFile;
use App\Dossier\Repository\DossierRepository;
use App\Dossier\Service\ContactDossierConverter;
use App\Dossier\Service\DocumentStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

// Same security model as other admin controllers: access_control on the
// prefix in security.yaml + hash_equals here. A wrong-but-format-valid
// prefix returns 404 before triggering any auth challenge.
#[Route(
    path: [
        'fr' => '/{_locale}/{adminPrefix}/admin',
        'en' => '/{_locale}/{adminPrefix}/admin',
    ],
    name: 'admin_',
    requirements: [
        '_locale' => 'fr|en',
        'adminPrefix' => '[a-zA-Z0-9_-]{16,64}',
    ],
)]
final class DossierController extends AbstractController
{
    public function __construct(
        #[Autowire('%admin_path_prefix%')]
        private readonly string $adminPathPrefix,
    ) {
    }

    #[Route(
        path: [
            'fr' => '/dossiers',
            'en' => '/files',
        ],
        name: 'dossiers',
        methods: ['GET'],
    )]
    public function index(string $adminPrefix): Response
    {
        $this->ensureValidPrefix($adminPrefix);

        // The list itself lives in the Dossier:DossierList live component.
        return $this->render('admin/dossiers/index.html.twig', [
            'adminPrefix' => $adminPrefix,
        ]);
    }

    #[Route(
        path: [
            'fr' => '/dossiers/{reference}',
            'en' => '/files/{reference}',
        ],
        name: 'dossier_show',
        requirements: ['reference' => 'DS-\d{6}'],
        methods: ['GET'],
    )]
    public function show(string $adminPrefix, string $reference, DossierRepository $repository): Response
    {
        $this->ensureValidPrefix($adminPrefix);

        $dossier = $repository->findDetailsByReference($reference);
        if (null === $dossier) {
            throw $this->createNotFoundException();
        }

        return $this->render('admin/dossiers/show.html.twig', [
            'adminPrefix' => $adminPrefix,
            'dossier' => $dossier,
            'adjacent' => $repository->findAdjacentReferences($reference),
        ]);
    }

    /**
     * "Transformer en dossier" on the contact detail page: the contact
     * becomes the dossier's primary tenant. Idempotent (same contact email →
     * same dossier), always lands on the dossier detail page.
     */
    #[Route(
        path: [
            'fr' => '/dossiers/depuis-contact/{reference}',
            'en' => '/files/from-contact/{reference}',
        ],
        name: 'dossier_from_contact',
        methods: ['POST'],
    )]
    public function createFromContact(
        string $adminPrefix,
        string $reference,
        Request $request,
        EntityManagerInterface $em,
        ContactDossierConverter $converter,
    ): Response {
        $this->ensureValidPrefix($adminPrefix);

        if (!$this->isCsrfTokenValid('dossier_from_contact', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $contact = $em->getRepository(Contact::class)->findOneBy(['reference' => $reference])
            ?? throw $this->createNotFoundException();

        $dossier = $converter->convert($contact);

        return $this->redirectToRoute('admin_dossier_show', [
            'adminPrefix' => $adminPrefix,
            'reference' => $dossier->getReference(),
        ], Response::HTTP_SEE_OTHER);
    }

    /**
     * Streams a deposited file from storage/ (outside public/): every read
     * goes through the admin firewall, nothing is directly reachable.
     */
    #[Route(
        path: [
            'fr' => '/dossiers/{reference}/fichiers/{id}',
            'en' => '/files/{reference}/files/{id}',
        ],
        name: 'dossier_document_file',
        requirements: ['reference' => 'DS-\d{6}', 'id' => '\d+'],
        methods: ['GET'],
    )]
    public function documentFile(
        string $adminPrefix,
        string $reference,
        int $id,
        Request $request,
        EntityManagerInterface $em,
        DocumentStorage $storage,
    ): Response {
        $this->ensureValidPrefix($adminPrefix);

        $dossier = $em->getRepository(Dossier::class)->findOneBy(['reference' => $reference])
            ?? throw $this->createNotFoundException();

        $file = $em->getRepository(DossierDocumentFile::class)->find($id);
        if (null === $file || $file->getDocument()?->getPerson()?->getDossier() !== $dossier) {
            throw $this->createNotFoundException();
        }

        $path = $storage->path($dossier, $file);
        if (!is_file($path)) {
            throw $this->createNotFoundException();
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', (string) $file->getMimeType());
        $response->headers->set('X-LiteSpeed-Cache-Control', 'no-cache');
        $response->headers->addCacheControlDirective('no-store');
        $response->setContentDisposition(
            $request->query->getBoolean('download') ? ResponseHeaderBag::DISPOSITION_ATTACHMENT : ResponseHeaderBag::DISPOSITION_INLINE,
            (string) $file->getOriginalName(),
            'document-'.$id,
        );

        return $response;
    }

    /**
     * One-click archive of every deposited file of the dossier, one folder
     * per person, entries named after the piece type. Built in a temp file
     * removed after send.
     */
    #[Route(
        path: [
            'fr' => '/dossiers/{reference}/pieces.zip',
            'en' => '/files/{reference}/documents.zip',
        ],
        name: 'dossier_documents_zip',
        requirements: ['reference' => 'DS-\d{6}'],
        methods: ['GET'],
    )]
    public function documentsZip(
        string $adminPrefix,
        string $reference,
        EntityManagerInterface $em,
        DocumentStorage $storage,
        TranslatorInterface $translator,
    ): Response {
        $this->ensureValidPrefix($adminPrefix);

        $dossier = $em->getRepository(Dossier::class)->findOneBy(['reference' => $reference])
            ?? throw $this->createNotFoundException();

        $entries = [];
        foreach ($dossier->getPersons() as $person) {
            $personDir = $this->zipSafe(trim(trim((string) $person->getLastName()).' '.trim((string) $person->getFirstName())));
            foreach ($person->getDocuments() as $document) {
                $label = $this->zipSafe($translator->trans($document->getType()?->labelKey() ?? '', locale: 'fr'));
                foreach ($document->getFiles() as $index => $file) {
                    $path = $storage->path($dossier, $file);
                    if (!is_file($path)) {
                        continue;
                    }
                    $extension = pathinfo((string) $file->getStoredName(), \PATHINFO_EXTENSION);
                    $suffix = $document->getFiles()->count() > 1 ? '-'.($index + 1) : '';
                    $entries[$personDir.'/'.$label.$suffix.('' !== $extension ? '.'.$extension : '')] = $path;
                }
            }
        }
        if ([] === $entries) {
            throw $this->createNotFoundException('No deposited file on this dossier.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'dossier-zip');
        if (false === $tmp) {
            throw new \RuntimeException('Unable to create the temporary archive.');
        }
        $zip = new \ZipArchive();
        if (true !== $zip->open($tmp, \ZipArchive::OVERWRITE)) {
            throw new \RuntimeException('Unable to open the temporary archive.');
        }
        foreach ($entries as $entryName => $path) {
            $zip->addFile($path, $entryName);
        }
        $zip->close();

        $response = new BinaryFileResponse($tmp);
        $response->deleteFileAfterSend();
        $response->headers->set('Content-Type', 'application/zip');
        $response->headers->set('X-LiteSpeed-Cache-Control', 'no-cache');
        $response->headers->addCacheControlDirective('no-store');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $reference.'-pieces.zip');

        return $response;
    }

    /** File-system-safe zip entry segment (no slashes, no control chars). */
    private function zipSafe(string $value): string
    {
        $value = str_replace(['/', '\\'], '-', trim($value));
        $value = (string) preg_replace('/[[:cntrl:]]+/', '', $value);

        return '' !== $value ? $value : 'dossier';
    }

    private function ensureValidPrefix(string $adminPrefix): void
    {
        if (!hash_equals($this->adminPathPrefix, $adminPrefix)) {
            throw $this->createNotFoundException();
        }
    }
}
