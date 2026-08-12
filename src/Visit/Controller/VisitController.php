<?php

declare(strict_types=1);

namespace App\Visit\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

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
final class VisitController extends AbstractController
{
    public function __construct(
        #[Autowire('%admin_path_prefix%')]
        private readonly string $adminPathPrefix,
    ) {
    }

    #[Route(
        path: [
            'fr' => '/visites',
            'en' => '/visits',
        ],
        name: 'visits',
        methods: ['GET'],
    )]
    public function index(string $adminPrefix, \App\Visit\Repository\VisitRepository $visits, \Psr\Clock\ClockInterface $clock): Response
    {
        $this->ensureValidPrefix($adminPrefix);

        $today = $clock->now()->setTimezone(new \DateTimeZone('Europe/Paris'));

        return $this->render('admin/visits/index.html.twig', [
            'adminPrefix' => $adminPrefix,
            'upcomingCount' => $visits->countUpcoming($today),
            'archivedCount' => $visits->countArchived($today),
        ]);
    }

    #[Route(
        path: [
            'fr' => '/visites/nouvelle',
            'en' => '/visits/new',
        ],
        name: 'visit_new',
        methods: ['GET'],
    )]
    public function new(string $adminPrefix): Response
    {
        $this->ensureValidPrefix($adminPrefix);

        // The split form/summary screen lives in the Visit:VisitForm component.
        return $this->render('admin/visits/new.html.twig', [
            'adminPrefix' => $adminPrefix,
        ]);
    }

    #[Route(
        path: [
            'fr' => '/visites/archives',
            'en' => '/visits/archive',
        ],
        name: 'visits_archive',
        methods: ['GET'],
    )]
    public function archive(string $adminPrefix, \App\Visit\Repository\VisitRepository $visits, \Psr\Clock\ClockInterface $clock): Response
    {
        $this->ensureValidPrefix($adminPrefix);

        $today = $clock->now()->setTimezone(new \DateTimeZone('Europe/Paris'));

        // The paginated day groups live in the Visit:VisitArchive component.
        return $this->render('admin/visits/archive.html.twig', [
            'adminPrefix' => $adminPrefix,
            'upcomingCount' => $visits->countUpcoming($today),
            'archivedCount' => $visits->countArchived($today),
        ]);
    }

    #[Route(
        path: [
            'fr' => '/visites/{id}',
            'en' => '/visits/{id}',
        ],
        name: 'visit_show',
        requirements: ['id' => '\\d+'],
        methods: ['GET'],
    )]
    public function show(string $adminPrefix, int $id, \App\Visit\Repository\VisitRepository $visits): Response
    {
        $this->ensureValidPrefix($adminPrefix);

        $visit = $visits->findOneSummary($id) ?? throw $this->createNotFoundException();

        $map = null;
        if (null !== $visit->latitude && null !== $visit->longitude) {
            $point = new \Symfony\UX\Map\Point($visit->latitude, $visit->longitude);
            $map = (new \Symfony\UX\Map\Map('default'))
                ->center($point)
                ->zoom(15)
                ->options(new \Symfony\UX\Map\Bridge\Google\GoogleOptions(
                    mapTypeControl: false,
                    streetViewControl: false,
                    fullscreenControl: false,
                ))
                ->addMarker(new \Symfony\UX\Map\Marker(position: $point));
        }

        // Read model of the property photos: id + display name only, the
        // bytes are streamed by visit_photo_file.
        $photos = array_map(
            static fn (\App\Visit\Entity\VisitPhoto $photo): array => [
                'id' => (int) $photo->getId(),
                'originalName' => (string) $photo->getOriginalName(),
            ],
            $visits->find($id)?->getPhotos()->toArray() ?? [],
        );

        return $this->render('admin/visits/show.html.twig', [
            'adminPrefix' => $adminPrefix,
            'visit' => $visit,
            'visitMap' => $map,
            'photos' => array_values($photos),
        ]);
    }

    #[Route(
        path: [
            'fr' => '/visites/{id}/supprimer',
            'en' => '/visits/{id}/delete',
        ],
        name: 'visit_delete',
        requirements: ['id' => '\\d+'],
        methods: ['POST'],
    )]
    public function delete(string $adminPrefix, int $id, Request $request, \App\Visit\Repository\VisitRepository $visits, \Doctrine\ORM\EntityManagerInterface $em): Response
    {
        $this->ensureValidPrefix($adminPrefix);

        if (!$this->isCsrfTokenValid('delete_visit', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $visit = $visits->find($id) ?? throw $this->createNotFoundException();
        $em->remove($visit);
        $em->flush();

        return $this->redirectToRoute('admin_visits', ['adminPrefix' => $adminPrefix], 303);
    }

    #[Route(
        path: [
            'fr' => '/visites/{id}/statut',
            'en' => '/visits/{id}/status',
        ],
        name: 'visit_status',
        requirements: ['id' => '\\d+'],
        methods: ['POST'],
    )]
    public function status(string $adminPrefix, int $id, Request $request, \App\Visit\Repository\VisitRepository $visits, \Doctrine\ORM\EntityManagerInterface $em): Response
    {
        $this->ensureValidPrefix($adminPrefix);

        if (!$this->isCsrfTokenValid('visit_status', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $visit = $visits->find($id) ?? throw $this->createNotFoundException();
        $status = \App\Visit\Domain\VisitStatus::tryFrom((string) $request->request->get('status'))
            ?? throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Unknown visit status.');

        // The report survives status changes on purpose: it can be written
        // at any point of the visit's life, not only once it is done.
        $visit->setStatus($status);
        $em->flush();

        return $this->redirectToRoute('admin_visit_show', ['adminPrefix' => $adminPrefix, 'id' => $id], 303);
    }

    #[Route(
        path: [
            'fr' => '/visites/{id}/compte-rendu',
            'en' => '/visits/{id}/report',
        ],
        name: 'visit_report',
        requirements: ['id' => '\\d+'],
        methods: ['POST'],
    )]
    public function report(string $adminPrefix, int $id, Request $request, \App\Visit\Repository\VisitRepository $visits, \Doctrine\ORM\EntityManagerInterface $em): Response
    {
        $this->ensureValidPrefix($adminPrefix);

        if (!$this->isCsrfTokenValid('visit_report', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $visit = $visits->find($id) ?? throw $this->createNotFoundException();

        $report = trim((string) $request->request->get('report'));
        $feeling = '' !== (string) $request->request->get('feeling')
            ? (\App\Visit\Domain\ClientFeeling::tryFrom((string) $request->request->get('feeling'))
                ?? throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Unknown client feeling.'))
            : null;

        $visit->setReport('' !== $report ? mb_substr($report, 0, 5000) : null)
            ->setClientFeeling($feeling);
        $em->flush();

        return $this->redirectToRoute('admin_visit_show', ['adminPrefix' => $adminPrefix, 'id' => $id], 303);
    }

    /**
     * Property photos: plain multipart POST (LiveComponents don't carry
     * files), several files at once. Each valid image is stored in the
     * photo storage (disk or GCS bucket) under visits/<ref>/photos/;
     * invalid files are skipped with a flash, they never block the others.
     */
    #[Route(
        path: [
            'fr' => '/visites/{id}/photos',
            'en' => '/visits/{id}/photos',
        ],
        name: 'visit_photos_upload',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    public function uploadPhotos(
        string $adminPrefix,
        int $id,
        Request $request,
        \App\Visit\Repository\VisitRepository $visits,
        \App\Visit\Storage\VisitPhotoStorage $storage,
        \Symfony\Component\Validator\Validator\ValidatorInterface $validator,
        \Doctrine\ORM\EntityManagerInterface $em,
    ): Response {
        $this->ensureValidPrefix($adminPrefix);

        if (!$this->isCsrfTokenValid('visit_photos', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $visit = $visits->find($id) ?? throw $this->createNotFoundException();

        $rejected = 0;
        $constraint = new \Symfony\Component\Validator\Constraints\Image(
            maxSize: '10M',
            mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
        );
        foreach ($request->files->all('photos') as $file) {
            if (!$file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile
                || $validator->validate($file, $constraint)->count() > 0) {
                ++$rejected;
                continue;
            }

            $photo = (new \App\Visit\Entity\VisitPhoto())
                ->setOriginalName((string) $file->getClientOriginalName())
                ->setMimeType((string) $file->getMimeType())
                ->setCreatedAt(new \DateTimeImmutable())
                ->setPath($storage->store((string) $visit->getReference(), $file));
            $visit->addPhoto($photo);
        }
        $em->flush();

        if ($rejected > 0) {
            $this->addFlash('visit_photos_rejected', $rejected);
        }

        return $this->redirectToRoute('admin_visit_show', ['adminPrefix' => $adminPrefix, 'id' => $id], 303);
    }

    /**
     * Streams a photo from storage (disk or GCS): every read goes through
     * the admin firewall, nothing is directly reachable.
     */
    #[Route(
        path: [
            'fr' => '/visites/{id}/photos/{photoId}',
            'en' => '/visits/{id}/photos/{photoId}',
        ],
        name: 'visit_photo_file',
        requirements: ['id' => '\d+', 'photoId' => '\d+'],
        methods: ['GET'],
    )]
    public function photoFile(
        string $adminPrefix,
        int $id,
        int $photoId,
        \App\Visit\Storage\VisitPhotoStorage $storage,
        \Doctrine\ORM\EntityManagerInterface $em,
    ): Response {
        $this->ensureValidPrefix($adminPrefix);

        $photo = $em->find(\App\Visit\Entity\VisitPhoto::class, $photoId);
        if (null === $photo || $photo->getVisit()?->getId() !== $id || !$storage->exists((string) $photo->getPath())) {
            throw $this->createNotFoundException();
        }

        $response = new \Symfony\Component\HttpFoundation\StreamedResponse(static function () use ($storage, $photo): void {
            $stream = $storage->readStream((string) $photo->getPath());
            fpassthru($stream);
            fclose($stream);
        });
        $response->headers->set('Content-Type', (string) $photo->getMimeType());
        $response->headers->set('X-LiteSpeed-Cache-Control', 'no-cache');
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    #[Route(
        path: [
            'fr' => '/visites/{id}/photos/{photoId}/suppression',
            'en' => '/visits/{id}/photos/{photoId}/delete',
        ],
        name: 'visit_photo_delete',
        requirements: ['id' => '\d+', 'photoId' => '\d+'],
        methods: ['POST'],
    )]
    public function deletePhoto(
        string $adminPrefix,
        int $id,
        int $photoId,
        Request $request,
        \App\Visit\Storage\VisitPhotoStorage $storage,
        \Doctrine\ORM\EntityManagerInterface $em,
    ): Response {
        $this->ensureValidPrefix($adminPrefix);

        if (!$this->isCsrfTokenValid('visit_photo_delete', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $photo = $em->find(\App\Visit\Entity\VisitPhoto::class, $photoId);
        if (null === $photo || $photo->getVisit()?->getId() !== $id) {
            throw $this->createNotFoundException();
        }

        $storage->delete((string) $photo->getPath());
        $em->remove($photo);
        $em->flush();

        return $this->redirectToRoute('admin_visit_show', ['adminPrefix' => $adminPrefix, 'id' => $id], 303);
    }

    private function ensureValidPrefix(string $adminPrefix): void
    {
        if (!hash_equals($this->adminPathPrefix, $adminPrefix)) {
            throw $this->createNotFoundException();
        }
    }
}
