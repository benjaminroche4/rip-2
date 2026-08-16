<?php

declare(strict_types=1);

namespace App\Dossier\Controller;

use App\Dossier\Domain\DossierDocumentStatus;
use App\Dossier\Domain\DossierPersonRole;
use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierDocumentFile;
use App\Dossier\Entity\DossierPerson;
use App\Dossier\Form\DepositPairingType;
use App\Dossier\Repository\DossierRepository;
use App\Dossier\Service\DocumentStorage;
use App\Dossier\Service\DossierDocumentNamer;
use App\Dossier\Service\DossierEventLogger;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints\File as FileConstraint;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;

/**
 * Public deposit page for dossier documents. Access is paired, not
 * authenticated: the visitor gives the email of one of the dossier persons
 * plus the dossier pairing code, and the grant lives in the session. Every
 * paired person sees and can deposit every requested piece of the dossier
 * (cross-deposit is wanted: a couple or a guarantor deposits for others).
 */
final class DepositController extends AbstractController
{
    private const SESSION_KEY = 'dossier_deposit';

    /**
     * Sliding expiry window of the pairing code, re-armed by every email
     * embedding the code (dossier creation, document request, reminder,
     * refusal). An expired code pairs exactly like an unknown one.
     */
    private const PAIRING_CODE_TTL = '-'.Dossier::PAIRING_CODE_TTL_DAYS.' days';

    /**
     * Hard expiry of a paired session grant. Generous on purpose (a deposit
     * spread over several evenings must survive), but a pairing done on a
     * shared computer cannot stay valid forever.
     */
    private const SESSION_GRANT_TTL = '-7 days';

    /** One-shot session slot carrying the ?code= of an email link across
        the redirect that strips it from the URL (keeps it out of analytics). */
    private const PREFILL_KEY = 'dossier_deposit_prefill';

    public function __construct(
        private readonly DossierRepository $dossiers,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        private readonly TranslatorInterface $translator,
        private readonly RateLimiterFactoryInterface $formDepositLimiter,
        private readonly RateLimiterFactoryInterface $formDepositUploadLimiter,
        private readonly DossierEventLogger $events,
        private readonly DossierDocumentNamer $namer,
        #[Autowire(service: 'monolog.logger.security')]
        private readonly LoggerInterface $securityLogger,
    ) {
    }

    #[Route(
        path: [
            'fr' => '/{_locale}/depot-de-pieces',
            'en' => '/{_locale}/document-upload',
        ],
        name: 'app_dossier_deposit',
        methods: ['GET', 'POST'],
        // Volontairement hors sitemap (et noindex) : page d'accès privée,
        // les seuls liens légitimes arrivent par email.
    )]
    public function index(Request $request, #[MapQueryParameter] ?string $code = null): Response
    {
        // The email links carry the pairing code in the query string. Strip
        // it immediately (303 to the clean URL, code parked in session):
        // otherwise the secret leaks to analytics (page_location), browser
        // history sync and any crawler that finds a pasted link.
        if (null !== $code && '' !== $this->normalizeCode($code)) {
            $request->getSession()->set(self::PREFILL_KEY, $this->normalizeCode($code));

            return $this->redirectToRoute('app_dossier_deposit', status: Response::HTTP_SEE_OTHER);
        }

        [$dossier, $person] = $this->pairedAccess($request);
        if (null !== $dossier && null !== $person) {
            return $this->uncacheable($this->render('public/dossier/deposit.html.twig', [
                'paired' => true,
                'deposit' => $this->buildView($dossier, $person),
            ]));
        }

        $form = $this->createForm(DepositPairingType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$this->formDepositLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
                $form->addError(new FormError($this->translator->trans('deposit.pairing.error.tooManyRequests')));

                return $this->pairingErrorResponse($request, $form);
            }

            $match = $this->match(
                (string) $form->get('email')->getData(),
                (string) $form->get('code')->getData(),
                $failureReason,
            );

            if (null === $match) {
                // Generic message on purpose: never reveal whether the code
                // exists, expired, or whether the email belongs to the
                // dossier. The reason stays internal, for the audit log only.
                $this->securityLogger->warning('Deposit pairing failed', [
                    'ip' => $request->getClientIp(),
                    'reason' => $failureReason ?? 'unknown',
                ]);
                $form->addError(new FormError($this->translator->trans('deposit.pairing.error.noMatch')));

                return $this->pairingErrorResponse($request, $form);
            }

            [$dossier, $person] = $match;
            // Verrou temporaire posé par le staff : message dédié (pas de
            // fuite sur le code : on ne l'affiche qu'après un appairage
            // valide), aucune session créée.
            if ($dossier->isDepositLocked()) {
                $this->securityLogger->warning('Deposit pairing refused: locked dossier', [
                    'dossier' => (string) $dossier->getReference(),
                    'ip' => $request->getClientIp(),
                ]);
                $form->addError(new FormError($this->translator->trans('deposit.pairing.error.depositLocked')));

                return $this->pairingErrorResponse($request, $form);
            }
            // New privilege level: rotate the session id (fixation guard).
            $request->getSession()->migrate();
            $request->getSession()->remove(self::PREFILL_KEY);
            $request->getSession()->set(self::SESSION_KEY, [
                'dossier' => (int) $dossier->getId(),
                'person' => (int) $person->getId(),
                'paired_at' => $this->clock->now()->getTimestamp(),
            ]);
            $this->securityLogger->info('Deposit pairing succeeded', [
                'dossier' => (string) $dossier->getReference(),
                'person' => (int) $person->getId(),
                'ip' => $request->getClientIp(),
            ]);

            return $this->redirectToRoute('app_dossier_deposit', status: Response::HTTP_SEE_OTHER);
        }

        if ($form->isSubmitted()) {
            return $this->pairingErrorResponse($request, $form);
        }

        // Kept in session (not one-shot) so a locale switch or a page reload
        // does not lose the prefill; cleared once the pairing succeeds.
        $prefill = (string) $request->getSession()->get(self::PREFILL_KEY, '');

        return $this->uncacheable($this->render('public/dossier/deposit.html.twig', [
            'paired' => false,
            'pairingForm' => $form->createView(),
            'prefilledCode' => $prefill,
        ]));
    }

    #[Route(
        path: [
            'fr' => '/{_locale}/depot-de-pieces/{id}',
            'en' => '/{_locale}/document-upload/{id}',
        ],
        name: 'app_dossier_deposit_upload',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    public function upload(int $id, Request $request, ValidatorInterface $validator, DocumentStorage $storage): Response
    {
        [$dossier, $person] = $this->pairedAccess($request);
        if (null === $dossier || null === $person) {
            return $this->redirectToRoute('app_dossier_deposit', status: Response::HTTP_SEE_OTHER);
        }

        if (!$this->isCsrfTokenValid('deposit_upload', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if (!$this->formDepositUploadLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return $this->documentsResponse($request, $dossier, $person, $id, 'deposit.documents.error.tooManyRequests');
        }

        $document = null;
        foreach ($dossier->getPersons() as $candidate) {
            foreach ($candidate->getDocuments() as $doc) {
                if ($doc->getId() === $id) {
                    $document = $doc;
                }
            }
        }
        if (null === $document) {
            throw $this->createNotFoundException('Document not found on this dossier.');
        }

        // Validated = frozen. The template already hides the input, but the
        // rule must hold server-side: re-uploading would silently demote the
        // piece to "received" and unlock the delete of the validated file.
        if (false === $document->getStatus()?->acceptsUpload()) {
            return $this->documentsResponse($request, $dossier, $person, $id, 'deposit.documents.error.locked');
        }

        $upload = $request->files->get('file');
        if (!$upload instanceof UploadedFile) {
            return $this->documentsResponse($request, $dossier, $person, $id, 'deposit.documents.error.noFile');
        }

        $violations = $validator->validate($upload, new FileConstraint(
            maxSize: '10M',
            // Same list as the staff upload (DossierController): tenants
            // photograph their documents, the image is stored as-is.
            mimeTypes: ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'],
            mimeTypesMessage: 'deposit.documents.error.mimeType',
            maxSizeMessage: 'deposit.documents.error.maxSize',
        ));
        if (\count($violations) > 0) {
            $message = (string) $violations->get(0)->getMessage();

            return $this->documentsResponse($request, $dossier, $person, $id, $message);
        }

        // Coherent display name ("Pièce d'identité - Jean Dupont.pdf")
        // instead of the client's own file name.
        $originalName = $this->namer->displayName($document, (string) ($upload->guessExtension() ?? 'pdf'));
        $mimeType = (string) ($upload->getMimeType() ?? 'application/octet-stream');
        $size = (int) $upload->getSize();
        try {
            $storedName = $storage->store($dossier, $document, $upload);
        } catch (\Throwable $e) {
            // Storage outage: show the depositor a retryable error instead
            // of a raw 500, and never persist a document row without a file.
            $this->securityLogger->error('Dossier document storage failed: '.$e->getMessage(), ['dossier' => $dossier->getReference()]);

            return $this->documentsResponse($request, $dossier, $person, $id, 'deposit.documents.error.storage');
        }

        $document->addFile((new DossierDocumentFile())
            ->setStoredName($storedName)
            ->setOriginalName($originalName)
            ->setMimeType($mimeType)
            ->setSize($size)
            ->setUploadedAt($this->clock->now())
            ->setUploadedBy($person));
        $document->setStatus(DossierDocumentStatus::Received);
        $document->setReceivedAt($this->clock->now());
        $depositor = trim(trim((string) $person->getFirstName()).' '.trim((string) $person->getLastName()));
        $this->events->log($dossier, 'document_deposited', [
            'piece' => $document->getType()?->labelKey() ?? '',
            'tenant' => trim(trim((string) $document->getPerson()?->getFirstName()).' '.trim((string) $document->getPerson()?->getLastName())),
            'file' => $originalName,
        ], authorName: $depositor);
        $this->em->flush();

        return $this->documentsResponse($request, $dossier, $person, null, null);
    }

    /**
     * Lets the paired person re-open a file they deposited, to check what
     * was sent. Same storage as the admin download, same no-store policy.
     */
    #[Route(
        path: [
            'fr' => '/{_locale}/depot-de-pieces/fichier/{id}',
            'en' => '/{_locale}/document-upload/file/{id}',
        ],
        name: 'app_dossier_deposit_file',
        requirements: ['id' => '\d+'],
        methods: ['GET'],
    )]
    public function viewFile(int $id, Request $request, DocumentStorage $storage): Response
    {
        [$dossier, $person] = $this->pairedAccess($request);
        if (null === $dossier || null === $person) {
            return $this->redirectToRoute('app_dossier_deposit', status: Response::HTTP_SEE_OTHER);
        }

        $file = $this->fileOfDossier($dossier, $id);
        if (!$storage->exists($dossier, $file)) {
            throw $this->createNotFoundException();
        }

        // Mirror of the admin-side audit: a hijacked paired session must
        // leave a trace of what it read.
        $this->securityLogger->info('Deposit document viewed', [
            'dossier' => (string) $dossier->getReference(),
            'file' => (int) $file->getId(),
            'person' => (int) $person->getId(),
            'ip' => $request->getClientIp(),
        ]);

        // Streamed from storage (disk or GCS bucket): the file is never
        // exposed by URL, only through this authenticated pass-through.
        $response = new StreamedResponse(static function () use ($storage, $dossier, $file): void {
            $stream = $storage->readStream($dossier, $file);
            fpassthru($stream);
            fclose($stream);
        });
        $response->headers->set('Content-Type', (string) $file->getMimeType());
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            (string) $file->getOriginalName(),
            'document-'.$id,
        ));

        return $this->uncacheable($response);
    }

    /**
     * Lets the paired person remove a file sent by mistake, as long as the
     * piece has not been validated yet. When the piece has no file left it
     * goes back to "requested".
     */
    #[Route(
        path: [
            'fr' => '/{_locale}/depot-de-pieces/fichier/{id}/suppression',
            'en' => '/{_locale}/document-upload/file/{id}/delete',
        ],
        name: 'app_dossier_deposit_file_delete',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    public function deleteFile(int $id, Request $request, DocumentStorage $storage): Response
    {
        [$dossier, $person] = $this->pairedAccess($request);
        if (null === $dossier || null === $person) {
            return $this->redirectToRoute('app_dossier_deposit', status: Response::HTTP_SEE_OTHER);
        }

        if (!$this->isCsrfTokenValid('deposit_delete', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if (!$this->formDepositUploadLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return $this->documentsResponse($request, $dossier, $person, null, 'deposit.documents.error.tooManyRequests');
        }

        $file = $this->fileOfDossier($dossier, $id);
        $document = $file->getDocument();

        if (DossierDocumentStatus::Validated === $document?->getStatus()) {
            return $this->documentsResponse($request, $dossier, $person, (int) $document->getId(), 'deposit.documents.error.locked');
        }

        $fileName = (string) $file->getOriginalName();
        $document?->removeFile($file);
        if (null !== $document && $document->getFiles()->isEmpty()) {
            $document->setStatus(DossierDocumentStatus::Requested);
            $document->setReceivedAt(null);
        }
        $this->events->log($dossier, 'document_file_removed', [
            'piece' => $document?->getType()?->labelKey() ?? '',
            'file' => $fileName,
        ], authorName: trim(trim((string) $person->getFirstName()).' '.trim((string) $person->getLastName())));
        // DB first: a storage hiccup after the flush leaves at worst an
        // orphan blob (cleaned by retention), never a DB row pointing at a
        // vanished file.
        $this->em->flush();
        try {
            $storage->delete($dossier, $file);
        } catch (\Throwable $e) {
            $this->securityLogger->warning('Deposit file blob deletion failed: '.$e->getMessage(), [
                'dossier' => (string) $dossier->getReference(),
                'file' => $id,
            ]);
        }

        return $this->documentsResponse($request, $dossier, $person, null, null);
    }

    /** "Not you?" link: drops the pairing grant and returns to the form. */
    #[Route(
        path: [
            'fr' => '/{_locale}/depot-de-pieces/quitter',
            'en' => '/{_locale}/document-upload/leave',
        ],
        name: 'app_dossier_deposit_leave',
        methods: ['POST'],
    )]
    public function leave(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('deposit_leave', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if (!$this->formDepositUploadLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return $this->redirectToRoute('app_dossier_deposit', status: Response::HTTP_SEE_OTHER);
        }

        $request->getSession()->remove(self::SESSION_KEY);

        return $this->redirectToRoute('app_dossier_deposit', status: Response::HTTP_SEE_OTHER);
    }

    /** Finds a deposited file within the paired dossier, 404 otherwise. */
    private function fileOfDossier(Dossier $dossier, int $id): DossierDocumentFile
    {
        foreach ($dossier->getPersons() as $person) {
            foreach ($person->getDocuments() as $document) {
                foreach ($document->getFiles() as $file) {
                    if ($file->getId() === $id) {
                        return $file;
                    }
                }
            }
        }

        throw $this->createNotFoundException('File not found on this dossier.');
    }

    /**
     * Deliberately does NOT check the pairing code's expiry: a session
     * already paired stays valid, the sliding 90-day window only guards NEW
     * pairings in match(). Kicking out a depositor mid-upload because the
     * code aged out would only hurt; closure remains the hard cut-off.
     *
     * @return array{0: Dossier|null, 1: DossierPerson|null}
     */
    private function pairedAccess(Request $request): array
    {
        $grant = $request->getSession()->get(self::SESSION_KEY);
        if (!\is_array($grant) || !isset($grant['dossier'], $grant['person'])) {
            return [null, null];
        }

        // Grants issued before the timestamp existed, or older than the TTL,
        // force a fresh pairing: a shared computer must not stay paired
        // forever, and 7 days never cuts a deposit session mid-flight.
        $pairedAt = (int) ($grant['paired_at'] ?? 0);
        if ($pairedAt < $this->clock->now()->modify(self::SESSION_GRANT_TTL)->getTimestamp()) {
            $request->getSession()->remove(self::SESSION_KEY);

            return [null, null];
        }

        $dossier = $this->dossiers->find((int) $grant['dossier']);
        if (!$dossier instanceof Dossier || $dossier->isClosed() || $dossier->isDepositLocked()) {
            return [null, null];
        }

        foreach ($dossier->getPersons() as $person) {
            if ($person->getId() === (int) $grant['person']) {
                return [$dossier, $person];
            }
        }

        return [null, null];
    }

    /**
     * @param 'unknown'|'expired'|null $failureReason internal audit-log detail,
     *                                                never shown to the visitor
     *
     * @return array{0: Dossier, 1: DossierPerson}|null
     */
    private function match(string $email, string $code, ?string &$failureReason = null): ?array
    {
        $failureReason = 'unknown';
        $code = $this->normalizeCode($code);
        $email = mb_strtolower(trim($email));
        if ('' === $code || '' === $email) {
            return null;
        }

        $dossier = $this->dossiers->findOneBy(['pairingCode' => $code]);
        if (!$dossier instanceof Dossier || $dossier->isClosed()) {
            // A closed dossier behaves exactly like an unknown code.
            return null;
        }

        // Sliding expiry window, re-armed by every email embedding the code:
        // an expired (or never armed) code behaves exactly like an unknown
        // one. Only NEW pairings are checked here — an already paired session
        // (pairedAccess) deliberately survives the code's expiry.
        $sentAt = $dossier->getPairingCodeSentAt();
        if (null === $sentAt || $sentAt < $this->clock->now()->modify(self::PAIRING_CODE_TTL)) {
            $failureReason = 'expired';

            return null;
        }

        foreach ($dossier->getPersons() as $person) {
            if (mb_strtolower(trim((string) $person->getEmail())) === $email) {
                $failureReason = null;

                return [$dossier, $person];
            }
        }

        return null;
    }

    private function normalizeCode(string $code): string
    {
        return strtoupper(trim($code));
    }

    private function pairingErrorResponse(Request $request, \Symfony\Component\Form\FormInterface $form): Response
    {
        // Turbo-driven invalid submission: stream with status 200 because
        // o2switch intercepts 4xx responses with its own error page.
        if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

            return $this->render('public/dossier/pairing.stream.html.twig', [
                'pairingForm' => $form->createView(),
                'prefilledCode' => '',
            ]);
        }

        $response = $this->render('public/dossier/deposit.html.twig', [
            'paired' => false,
            'pairingForm' => $form->createView(),
            'prefilledCode' => '',
        ]);
        $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);

        return $this->uncacheable($response);
    }

    private function documentsResponse(
        Request $request,
        Dossier $dossier,
        DossierPerson $person,
        ?int $errorDocumentId,
        ?string $errorKey,
    ): Response {
        if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

            return $this->render('public/dossier/documents.stream.html.twig', [
                'deposit' => $this->buildView($dossier, $person, $errorDocumentId, $errorKey),
            ]);
        }

        return $this->redirectToRoute('app_dossier_deposit', status: Response::HTTP_SEE_OTHER);
    }

    /**
     * View model of the deposit page: plain arrays only, never entities.
     *
     * @return array{
     *     reference: string,
     *     firstName: string,
     *     progress: array{validated: int, deposited: int, total: int},
     *     tenants: list<array{name: string, documents: list<array<string, mixed>>}>,
     *     errorDocumentId: int|null,
     *     errorKey: string|null,
     * }
     */
    private function buildView(
        Dossier $dossier,
        DossierPerson $person,
        ?int $errorDocumentId = null,
        ?string $errorKey = null,
    ): array {
        $tenants = [];
        $validated = 0;
        $deposited = 0;
        $total = 0;
        // Urgency order: what still needs an action comes first, validated
        // pieces sink to the bottom of each tenant list.
        $urgency = [
            DossierDocumentStatus::Refused->value => 0,
            DossierDocumentStatus::Requested->value => 1,
            DossierDocumentStatus::Received->value => 2,
            DossierDocumentStatus::Validated->value => 3,
        ];
        foreach ($dossier->getPersons() as $tenant) {
            $documents = [];
            foreach ($tenant->getDocuments() as $document) {
                $files = [];
                foreach ($document->getFiles() as $file) {
                    $files[] = [
                        'id' => (int) $file->getId(),
                        'name' => (string) $file->getOriginalName(),
                        'size' => (int) $file->getSize(),
                        'mimeType' => (string) $file->getMimeType(),
                        'uploadedAt' => $file->getUploadedAt(),
                    ];
                }
                $status = $document->getStatus();
                ++$total;
                if (DossierDocumentStatus::Validated === $status) {
                    ++$validated;
                }
                // "Deposited" = the tenant already did their part (a file is
                // in, awaiting review or validated): the honest progress.
                if (\in_array($status, [DossierDocumentStatus::Received, DossierDocumentStatus::Validated], true)) {
                    ++$deposited;
                }
                $documents[] = [
                    'id' => (int) $document->getId(),
                    'typeLabelKey' => $document->getType()?->labelKey() ?? '',
                    'description' => (string) $document->getDescription(),
                    'status' => $status->value,
                    'statusLabelKey' => $status->publicLabelKey(),
                    'canUpload' => $status->acceptsUpload(),
                    'canDeleteFiles' => DossierDocumentStatus::Validated !== $status,
                    'receivedAt' => $document->getReceivedAt(),
                    'refusalReason' => (string) $document->getRefusalReason(),
                    'files' => $files,
                ];
            }
            if ([] === $documents) {
                continue;
            }
            usort($documents, static fn (array $a, array $b): int => $urgency[$a['status']] <=> $urgency[$b['status']]);
            $tenants[] = [
                'name' => trim(trim((string) $tenant->getFirstName()).' '.trim((string) $tenant->getLastName())),
                'documents' => $documents,
            ];
        }

        // Greeting: every tenant first name ("Jean et Marie"), so a couple
        // feels addressed together whoever paired. Falls back to the paired
        // person (a guarantor or follow-up may pair on a dossier whose
        // tenants have no usable first name).
        $tenantFirstNames = [];
        foreach ($dossier->getPersons() as $candidate) {
            $firstName = trim((string) $candidate->getFirstName());
            if (DossierPersonRole::TENANT === $candidate->getRole() && '' !== $firstName) {
                $tenantFirstNames[] = $firstName;
            }
        }
        if ([] === $tenantFirstNames) {
            $tenantFirstNames = [trim((string) $person->getFirstName())];
        }

        return [
            'reference' => (string) $dossier->getReference(),
            'firstName' => implode(' '.$this->translator->trans('deposit.documents.nameJoin').' ', $tenantFirstNames),
            'progress' => ['validated' => $validated, 'deposited' => $deposited, 'total' => $total],
            'tenants' => $tenants,
            'errorDocumentId' => $errorDocumentId,
            'errorKey' => $errorKey,
        ];
    }

    /**
     * The page is session-personalized: LiteSpeed on o2switch ignores
     * Cache-Control private, so pin no-store + the LiteSpeed header.
     */
    private function uncacheable(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-LiteSpeed-Cache-Control', 'no-cache');

        return $response;
    }
}
