<?php

declare(strict_types=1);

namespace App\Dossier\Controller;

use App\Contact\Entity\Contact;
use App\Dossier\Domain\DossierStep;
use App\Dossier\Domain\DossierStepView;
use App\Dossier\Domain\DossierDocumentStatus;
use App\Dossier\Domain\DossierPersonRole;
use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierDocument;
use App\Dossier\Entity\DossierDocumentFile;
use App\Dossier\Service\DossierDocumentNamer;
use App\Dossier\Service\DossierEventLogger;
use App\Dossier\Repository\DossierRepository;
use App\Dossier\Service\ContactDossierConverter;
use App\Dossier\Service\DocumentStorage;
use App\Dossier\Service\DossierDriveProvisioner;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints\File as FileConstraint;
use Symfony\Component\Validator\Validator\ValidatorInterface;
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
    /** Vue d'ensemble : toujours accessible, hors du parcours d'étapes. */
    private const RECAP_TAB = 'recap';

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
    public function index(
        string $adminPrefix,
        DossierRepository $repository,
        \Symfony\Component\Clock\ClockInterface $clock,
    ): Response {
        $this->ensureValidPrefix($adminPrefix);

        // The list itself lives in the Dossier:DossierList live component.
        // The expired-pairing-code alert only renders when it has something
        // to say (open dossiers, dead code, pieces still awaited).
        return $this->render('admin/dossiers/index.html.twig', [
            'adminPrefix' => $adminPrefix,
            'expiredPairingAlerts' => $repository->findExpiredPairingAlerts(
                $clock->now()->modify(\sprintf('-%d days', Dossier::PAIRING_CODE_TTL_DAYS)),
            ),
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
    public function show(
        string $adminPrefix,
        string $reference,
        DossierRepository $repository,
        \App\Dossier\Service\DossierProgressCalculator $calculator,
        #[MapQueryParameter]
        ?string $tab = null,
    ): Response {
        $this->ensureValidPrefix($adminPrefix);

        $dossier = $repository->findDetailsByReference($reference);
        $entity = $repository->findOneBy(['reference' => $reference]);
        if (null === $dossier || null === $entity) {
            throw $this->createNotFoundException();
        }

        // Parcours de gauche à droite : chaque étape ouvre la suivante. Les
        // onglets restent visibles (cadenas) pour que le chemin complet se
        // lise dès l'arrivée.
        $progress = $calculator->forDossier($entity);
        $steps = array_map(
            static fn (DossierStep $step): DossierStepView => DossierStepView::fromStep(
                $step,
                $progress,
                DossierStep::Persons === $step ? \count($dossier->persons) : null,
            ),
            DossierStep::ordered(),
        );

        return $this->render('admin/dossiers/show.html.twig', [
            'adminPrefix' => $adminPrefix,
            'dossier' => $dossier,
            'adjacent' => $repository->findAdjacentReferences($reference),
            'steps' => $steps,
            'activeTab' => $this->resolveTab($tab, $steps),
            'currentStep' => $progress->currentStep()?->value,
        ]);
    }

    /**
     * Onglet ouvert au chargement : celui de l'URL s'il est déverrouillé,
     * sinon le récap. Un lien vers une étape encore fermée ne doit jamais
     * afficher un panneau interdit.
     *
     * @param list<DossierStepView> $steps
     */
    private function resolveTab(?string $requested, array $steps): string
    {
        if (null === $requested || self::RECAP_TAB === $requested) {
            return self::RECAP_TAB;
        }

        foreach ($steps as $step) {
            if ($step->key === $requested) {
                return $step->unlocked ? $step->key : self::RECAP_TAB;
            }
        }

        return self::RECAP_TAB;
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
        requirements: ['reference' => 'CT-\\d{6}'],
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
        // Converting reads the lead's PII (identity, notes) into a dossier:
        // it requires the contacts section, not just the dossiers one.
        $this->denyAccessUnlessGranted('ROLE_SECTION_CONTACTS');

        if (!$this->isCsrfTokenValid('dossier_from_contact', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $contact = $em->getRepository(Contact::class)->findOneBy(['reference' => $reference])
            ?? throw $this->createNotFoundException();

        // Formule choisie dans la modale quand le lead n'en portait pas :
        // posée sur le contact avant conversion, le converter la copie sur
        // le dossier (et les deux fiches restent alignées). Un dossier porte
        // toujours une formule : sans elle, la conversion est refusée (les
        // radios required couvrent le navigateur, ceci couvre le reste).
        $offer = (string) $request->request->get('offer', '');
        if (null === $contact->getOffer() && \in_array($offer, ['accompagne', 'confie'], true)) {
            $contact->setOffer($offer);
        }
        if (null === $contact->getOffer()) {
            $this->addFlash('error', 'admin.dossiers.create.offer.required');

            return $this->redirectToRoute('admin_contact_show', [
                'adminPrefix' => $adminPrefix,
                'reference' => $contact->getReference(),
            ], Response::HTTP_SEE_OTHER);
        }

        $dossier = $converter->convert($contact);

        return $this->redirectToRoute('admin_dossier_show', [
            'adminPrefix' => $adminPrefix,
            'reference' => $dossier->getReference(),
        ], Response::HTTP_SEE_OTHER);
    }

    /**
     * Deletes the dossier for good, from the "Actions" menu of the detail
     * page: deposited files first (so nothing is left in storage), then the
     * Drive folder, then the record. Persons, documents, notes, search,
     * events and visits follow through the ORM and database cascades.
     * Written to the security audit channel: this destroys client data.
     */
    #[Route(
        path: [
            'fr' => '/dossiers/{reference}/supprimer',
            'en' => '/files/{reference}/delete',
        ],
        name: 'dossier_delete',
        requirements: ['reference' => 'DS-\\d{6}'],
        methods: ['POST'],
    )]
    public function delete(
        string $adminPrefix,
        string $reference,
        Request $request,
        EntityManagerInterface $em,
        DossierRepository $repository,
        DocumentStorage $storage,
        DossierDriveProvisioner $drive,
        Security $security,
        #[Autowire(service: 'monolog.logger.security')]
        LoggerInterface $securityLogger,
    ): Response {
        $this->ensureValidPrefix($adminPrefix);
        $this->denyAccessUnlessGranted('ROLE_SECTION_DOSSIERS');

        if (!$this->isCsrfTokenValid('delete_dossier', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $dossier = $repository->findOneBy(['reference' => $reference])
            ?? throw $this->createNotFoundException();

        foreach ($dossier->getPersons() as $person) {
            foreach ($person->getDocuments() as $document) {
                foreach ($document->getFiles() as $file) {
                    // A storage outage must not block the deletion: the
                    // Drive folder purge below is the safety net.
                    try {
                        $storage->delete($dossier, $file);
                    } catch (\Throwable) {
                    }
                }
            }
        }
        $drive->deleteDossierFolder($dossier);

        $securityLogger->notice('Dossier deleted', [
            'actor' => $security->getUser()?->getUserIdentifier(),
            'dossier' => $reference,
        ]);

        $em->remove($dossier);
        $em->flush();

        return $this->redirectToRoute('admin_dossiers', ['adminPrefix' => $adminPrefix], Response::HTTP_SEE_OTHER);
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
        #[Autowire(service: 'monolog.logger.security')]
        LoggerInterface $securityLogger,
    ): Response {
        $this->ensureValidPrefix($adminPrefix);

        $dossier = $em->getRepository(Dossier::class)->findOneBy(['reference' => $reference])
            ?? throw $this->createNotFoundException();

        $file = $em->getRepository(DossierDocumentFile::class)->find($id);
        if (null === $file || $file->getDocument()?->getPerson()?->getDossier() !== $dossier) {
            throw $this->createNotFoundException();
        }

        if (!$storage->exists($dossier, $file)) {
            throw $this->createNotFoundException();
        }

        // Audit trail: dossier pieces are sensitive documents, every read
        // is traced (who accessed which file of which dossier).
        $securityLogger->notice('Dossier document accessed', [
            'actor' => $this->getUser()?->getUserIdentifier(),
            'dossier' => $reference,
            'file' => $id,
            'download' => $request->query->getBoolean('download'),
        ]);

        // Streamed from storage (disk or GCS bucket): the file is never
        // exposed by URL, only through this authenticated pass-through.
        $response = new StreamedResponse(static function () use ($storage, $dossier, $file): void {
            $stream = $storage->readStream($dossier, $file);
            fpassthru($stream);
            fclose($stream);
        });
        $response->headers->set('Content-Type', (string) $file->getMimeType());
        $response->headers->set('X-LiteSpeed-Cache-Control', 'no-cache');
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            $request->query->getBoolean('download') ? ResponseHeaderBag::DISPOSITION_ATTACHMENT : ResponseHeaderBag::DISPOSITION_INLINE,
            (string) $file->getOriginalName(),
            'document-'.$id,
        ));

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
        #[Autowire(service: 'monolog.logger.security')]
        LoggerInterface $securityLogger,
    ): Response {
        $this->ensureValidPrefix($adminPrefix);

        $dossier = $em->getRepository(Dossier::class)->findOneBy(['reference' => $reference])
            ?? throw $this->createNotFoundException();

        // Audit trail: a full-dossier export is the heaviest document read.
        $securityLogger->notice('Dossier documents archive downloaded', [
            'actor' => $this->getUser()?->getUserIdentifier(),
            'dossier' => $reference,
        ]);

        $entries = [];
        foreach ($dossier->getPersons() as $person) {
            $personDir = $this->zipSafe(trim(trim((string) $person->getLastName()).' '.trim((string) $person->getFirstName())));
            foreach ($person->getDocuments() as $document) {
                $label = $this->zipSafe($translator->trans($document->getType()?->labelKey() ?? '', locale: 'fr'));
                foreach ($document->getFiles() as $index => $file) {
                    if (!$storage->exists($dossier, $file)) {
                        continue;
                    }
                    // The original name carries the real extension; the stored
                    // name is a Drive file id in drive mode (no extension at
                    // all), which used to produce unopenable entries.
                    $extension = strtolower(pathinfo((string) $file->getOriginalName(), \PATHINFO_EXTENSION));
                    if ('' === $extension) {
                        $extension = strtolower(pathinfo((string) $file->getStoredName(), \PATHINFO_EXTENSION));
                    }
                    $suffix = $document->getFiles()->count() > 1 ? '-'.($index + 1) : '';
                    $entries[$personDir.'/'.$label.$suffix.('' !== $extension ? '.'.$extension : '')] = $file;
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
        // The contents are pulled from storage (disk or GCS): each file goes
        // through a temp copy so ZipArchive can add it, whatever the backend.
        $tmpFiles = [];
        foreach ($entries as $entryName => $file) {
            $tmpFile = tempnam(sys_get_temp_dir(), 'dossier-zip-entry');
            if (false === $tmpFile) {
                continue;
            }
            $source = null;
            $target = fopen($tmpFile, 'w');
            try {
                if (false === $target) {
                    @unlink($tmpFile);
                    continue;
                }
                $source = $storage->readStream($dossier, $file);
                stream_copy_to_stream($source, $target);
                $tmpFiles[] = $tmpFile;
                $zip->addFile($tmpFile, $entryName);
            } catch (\Throwable) {
                // Skip an unreadable file instead of aborting the whole zip;
                // the temp file is cleaned right here.
                @unlink($tmpFile);
            } finally {
                if (\is_resource($source)) {
                    fclose($source);
                }
                if (\is_resource($target)) {
                    fclose($target);
                }
            }
        }
        $zip->close();
        // ZipArchive reads the sources at close(): only clean up afterwards.
        foreach ($tmpFiles as $tmpFile) {
            @unlink($tmpFile);
        }

        $response = new BinaryFileResponse($tmp);
        $response->deleteFileAfterSend();
        $response->headers->set('Content-Type', 'application/zip');
        $response->headers->set('X-LiteSpeed-Cache-Control', 'no-cache');
        $response->headers->addCacheControlDirective('no-store');
        // "Nom Prénom - DS-000042.zip", named after the primary tenant so
        // the archive is self-explanatory once saved among others.
        $primary = null;
        foreach ($dossier->getPersons() as $person) {
            if (DossierPersonRole::TENANT !== $person->getRole()) {
                continue;
            }
            if ($person->isPrimaryContact()) {
                $primary = $person;
                break;
            }
            $primary ??= $person;
        }
        $who = null !== $primary
            ? $this->zipSafe(trim(trim((string) $primary->getLastName()).' '.trim((string) $primary->getFirstName())))
            : '';
        $zipName = ('' !== $who ? $who.' - ' : '').$reference.'.zip';
        // ASCII fallback for the Content-Disposition header (accents in
        // tenant names would make the raw header value invalid).
        $fallback = trim((string) preg_replace('/[^\x20-\x7E]/', '', $zipName));
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $zipName, '' !== $fallback ? $fallback : $reference.'.zip');

        return $response;
    }

    /** File-system-safe zip entry segment (no slashes, no control chars). */
    private function zipSafe(string $value): string
    {
        $value = str_replace(['/', '\\'], '-', trim($value));
        $value = (string) preg_replace('/[[:cntrl:]]+/', '', $value);

        return '' !== $value ? $value : 'dossier';
    }


    /**
     * Dépôt d'une pièce par le staff : le locataire l'a envoyée en direct
     * (email, WhatsApp...), l'admin la verse au dossier comme un dépôt
     * classique. Miroir du flux public (stockage, statut, fil de suivi),
     * avec en plus les images acceptées (photos de documents).
     */
    #[Route(
        path: [
            'fr' => '/dossiers/{reference}/pieces/{id}/fichier',
            'en' => '/files/{reference}/documents/{id}/file',
        ],
        name: 'dossier_document_upload',
        requirements: ['reference' => 'DS-\d{6}', 'id' => '\d+'],
        methods: ['POST'],
    )]
    public function documentUpload(
        string $adminPrefix,
        string $reference,
        int $id,
        Request $request,
        EntityManagerInterface $em,
        DocumentStorage $storage,
        ValidatorInterface $validator,
        DossierEventLogger $events,
        ClockInterface $clock,
        #[Autowire(service: 'monolog.logger.security')]
        LoggerInterface $securityLogger,
        DossierDocumentNamer $namer,
    ): Response {
        $this->ensureValidPrefix($adminPrefix);

        if (!$this->isCsrfTokenValid('admin_document_upload', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $dossier = $em->getRepository(Dossier::class)->findOneBy(['reference' => $reference])
            ?? throw $this->createNotFoundException();
        $document = $em->getRepository(DossierDocument::class)->find($id);
        if (null === $document || $document->getPerson()?->getDossier() !== $dossier) {
            throw $this->createNotFoundException();
        }

        $redirect = $this->redirectToRoute('admin_dossier_show', [
            'adminPrefix' => $adminPrefix,
            'reference' => $reference,
            'tab' => 'file',
        ], Response::HTTP_SEE_OTHER);

        $upload = $request->files->get('file');
        if (!$upload instanceof UploadedFile) {
            $this->addFlash('error', 'admin.dossiers.show.modules.file.upload.noFile');

            return $redirect;
        }

        $violations = $validator->validate($upload, new FileConstraint(
            maxSize: '10M',
            mimeTypes: ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'],
            mimeTypesMessage: 'admin.dossiers.show.modules.file.upload.invalid',
            maxSizeMessage: 'admin.dossiers.show.modules.file.upload.invalid',
        ));
        if (\count($violations) > 0) {
            $this->addFlash('error', 'admin.dossiers.show.modules.file.upload.invalid');

            return $redirect;
        }

        // Coherent display name ("Pièce d'identité - Jean Dupont.pdf")
        // instead of the picked file's own name, same rule as the tenant
        // deposit page.
        $originalName = $namer->displayName($document, (string) ($upload->guessExtension() ?? 'pdf'));
        $mimeType = (string) ($upload->getMimeType() ?? 'application/octet-stream');
        $size = (int) $upload->getSize();
        try {
            $storedName = $storage->store($dossier, $document, $upload);
        } catch (\Throwable $e) {
            $securityLogger->error('Dossier document storage failed: '.$e->getMessage(), ['dossier' => $dossier->getReference()]);
            $this->addFlash('error', 'admin.dossiers.show.modules.file.upload.storage');

            return $redirect;
        }

        $document->addFile((new DossierDocumentFile())
            ->setStoredName($storedName)
            ->setOriginalName($originalName)
            ->setMimeType($mimeType)
            ->setSize($size)
            ->setUploadedAt($clock->now()));
        // Une pièce encore attendue passe "Déposée"; une pièce déjà validée
        // garde son statut (l'admin complète, il ne rétrograde pas).
        if (\in_array($document->getStatus(), [DossierDocumentStatus::Requested, DossierDocumentStatus::Refused], true)) {
            $document->setStatus(DossierDocumentStatus::Received);
        }
        if (null === $document->getReceivedAt()) {
            $document->setReceivedAt($clock->now());
        }
        // Fil de suivi : même événement qu'un dépôt, l'auteur (admin) est
        // porté par le logger.
        $events->log($dossier, 'document_deposited', [
            'piece' => $document->getType()?->labelKey() ?? '',
            'tenant' => trim(trim((string) $document->getPerson()->getFirstName()).' '.trim((string) $document->getPerson()->getLastName())),
            'file' => $originalName,
        ]);
        $em->flush();

        $this->addFlash('success', 'admin.toast.fileUploaded');

        return $redirect;
    }

    private function ensureValidPrefix(string $adminPrefix): void
    {
        if (!hash_equals($this->adminPathPrefix, $adminPrefix)) {
            throw $this->createNotFoundException();
        }
    }
}
