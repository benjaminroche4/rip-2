<?php

declare(strict_types=1);

namespace App\Dossier\Controller;

use App\Dossier\Domain\DossierDocumentStatus;
use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierDocumentFile;
use App\Dossier\Entity\DossierPerson;
use App\Dossier\Form\DepositPairingType;
use App\Dossier\Repository\DossierRepository;
use App\Dossier\Service\DocumentStorage;
use App\Dossier\Service\DossierEventLogger;
use Doctrine\ORM\EntityManagerInterface;
use Presta\SitemapBundle\Sitemap\Url\UrlConcrete;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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

    public function __construct(
        private readonly DossierRepository $dossiers,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        private readonly TranslatorInterface $translator,
        private readonly RateLimiterFactoryInterface $formDepositLimiter,
        private readonly DossierEventLogger $events,
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
        options: [
            // Référencée volontairement très bas : page utilitaire, pas
            // une page d'acquisition.
            'sitemap' => [
                'priority' => 0.1,
                'changefreq' => UrlConcrete::CHANGEFREQ_YEARLY,
            ],
        ],
    )]
    public function index(Request $request): Response
    {
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
            );

            if (null === $match) {
                // Generic message on purpose: never reveal whether the code
                // exists or whether the email belongs to the dossier.
                $this->securityLogger->warning('Deposit pairing failed', [
                    'ip' => $request->getClientIp(),
                ]);
                $form->addError(new FormError($this->translator->trans('deposit.pairing.error.noMatch')));

                return $this->pairingErrorResponse($request, $form);
            }

            [$dossier, $person] = $match;
            // New privilege level: rotate the session id (fixation guard).
            $request->getSession()->migrate();
            $request->getSession()->set(self::SESSION_KEY, [
                'dossier' => (int) $dossier->getId(),
                'person' => (int) $person->getId(),
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

        return $this->uncacheable($this->render('public/dossier/deposit.html.twig', [
            'paired' => false,
            'pairingForm' => $form->createView(),
            'prefilledCode' => $this->normalizeCode((string) $request->query->get('code')),
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

        if (!$this->formDepositLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return $this->documentsResponse($request, $dossier, $person, $id, 'deposit.pairing.error.tooManyRequests');
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

        $upload = $request->files->get('file');
        if (!$upload instanceof UploadedFile) {
            return $this->documentsResponse($request, $dossier, $person, $id, 'deposit.documents.error.noFile');
        }

        $violations = $validator->validate($upload, new FileConstraint(
            maxSize: '10M',
            mimeTypes: ['application/pdf'],
            mimeTypesMessage: 'deposit.documents.error.mimeType',
            maxSizeMessage: 'deposit.documents.error.maxSize',
        ));
        if (\count($violations) > 0) {
            $message = (string) $violations->get(0)->getMessage();

            return $this->documentsResponse($request, $dossier, $person, $id, $message);
        }

        $originalName = substr((string) $upload->getClientOriginalName(), 0, 255);
        $mimeType = (string) ($upload->getMimeType() ?? 'application/octet-stream');
        $size = (int) $upload->getSize();
        $storedName = $storage->store($dossier, $upload);

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
        $path = $storage->path($dossier, $file);
        if (!is_file($path)) {
            throw $this->createNotFoundException();
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', (string) $file->getMimeType());
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            (string) $file->getOriginalName(),
            'document-'.$id,
        );

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

        $file = $this->fileOfDossier($dossier, $id);
        $document = $file->getDocument();

        if (DossierDocumentStatus::Validated === $document?->getStatus()) {
            return $this->documentsResponse($request, $dossier, $person, (int) $document->getId(), 'deposit.documents.error.locked');
        }

        $storage->delete($dossier, $file);
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
        $this->em->flush();

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
     * @return array{0: Dossier|null, 1: DossierPerson|null}
     */
    private function pairedAccess(Request $request): array
    {
        $grant = $request->getSession()->get(self::SESSION_KEY);
        if (!\is_array($grant) || !isset($grant['dossier'], $grant['person'])) {
            return [null, null];
        }

        $dossier = $this->dossiers->find((int) $grant['dossier']);
        if (!$dossier instanceof Dossier || $dossier->isClosed()) {
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
     * @return array{0: Dossier, 1: DossierPerson}|null
     */
    private function match(string $email, string $code): ?array
    {
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

        foreach ($dossier->getPersons() as $person) {
            if (mb_strtolower(trim((string) $person->getEmail())) === $email) {
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
     *     manager: array{name: string, email: string, phone: string}|null,
     *     progress: array{validated: int, total: int},
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
        $total = 0;
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
            $tenants[] = [
                'name' => trim(trim((string) $tenant->getFirstName()).' '.trim((string) $tenant->getLastName())),
                'documents' => $documents,
            ];
        }

        $manager = $dossier->getManager();

        return [
            'reference' => (string) $dossier->getReference(),
            'firstName' => trim((string) $person->getFirstName()),
            'manager' => null !== $manager ? [
                'name' => trim(trim((string) $manager->getFirstName()).' '.trim((string) $manager->getLastName())),
                'email' => (string) $manager->getEmail(),
                'phone' => (string) $manager->getPhoneNumber(),
                'avatarFilename' => $manager->getAvatarFilename(),
            ] : null,
            'progress' => ['validated' => $validated, 'total' => $total],
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
