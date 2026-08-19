<?php

declare(strict_types=1);

namespace App\Visit\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
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
    public function new(
        string $adminPrefix,
        #[MapQueryParameter] ?string $dossier = null,
        ?\App\Dossier\Repository\DossierRepository $dossiers = null,
    ): Response {
        $this->ensureValidPrefix($adminPrefix);

        // "Planifier une visite" depuis une fiche dossier : la référence en
        // query présélectionne le dossier dans le formulaire. Une référence
        // inconnue est simplement ignorée.
        $preselected = null;
        if (null !== $dossier && null !== $dossiers && 1 === preg_match('/^DS-\d{6}$/', $dossier)) {
            $preselected = $dossiers->findOneBy(['reference' => $dossier])?->getId();
        }

        // The split form/summary screen lives in the Visit:VisitForm component.
        return $this->render('admin/visits/new.html.twig', [
            'adminPrefix' => $adminPrefix,
            'preselectedDossierId' => $preselected,
        ]);
    }

    /**
     * "Modifier" : exactement la page de création, avec le formulaire monté
     * sur la visite existante (champs pré-remplis, dossier verrouillé,
     * section photos masquée). Les coordonnées déjà géocodées sont injectées
     * pour qu'un enregistrement sans changement d'adresse ne regéocode pas.
     */
    #[Route(
        path: [
            'fr' => '/visites/{reference}/modifier',
            'en' => '/visits/{reference}/edit',
        ],
        name: 'visit_edit',
        requirements: ['reference' => 'VS-\d{6}'],
        methods: ['GET'],
    )]
    public function edit(string $adminPrefix, string $reference, \App\Visit\Repository\VisitRepository $visits): Response
    {
        $this->ensureValidPrefix($adminPrefix);

        $visit = $visits->findOneBy(['reference' => $reference]) ?? throw $this->createNotFoundException();

        return $this->render('admin/visits/edit.html.twig', [
            'adminPrefix' => $adminPrefix,
            'visit' => $visit,
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

    // Référence publique (jamais l'id auto-incrémenté, non devinable), même
    // modèle que les agents (AG-), agences (AY-) et dossiers (DS-). Pas de
    // redirection de compatibilité id -> référence : les URLs sont internes
    // au back-office, l'ancien format tombe en 404 (décision assumée).
    #[Route(
        path: [
            'fr' => '/visites/{reference}',
            'en' => '/visits/{reference}',
        ],
        name: 'visit_show',
        requirements: ['reference' => 'VS-\d{6}'],
        methods: ['GET'],
    )]
    public function show(string $adminPrefix, string $reference, \App\Visit\Repository\VisitRepository $visits, \Psr\Clock\ClockInterface $clock): Response
    {
        $this->ensureValidPrefix($adminPrefix);

        $visit = $visits->findOneSummaryByReference($reference) ?? throw $this->createNotFoundException();

        // Badge compte à rebours de l'en-tête, même fenêtre que les lignes
        // de la liste (moins de deux heures) : comparaison en heures murales
        // Paris, le scheduledAt étant stocké en heure locale.
        $nowWall = $clock->now()
            ->setTimezone(new \DateTimeZone('Europe/Paris'))
            ->format('Y-m-d H:i:s');
        $minutes = (int) floor((strtotime($visit->scheduledAt->format('Y-m-d H:i:s')) - strtotime($nowWall)) / 60);
        $minutesUntil = $minutes > 0 && $minutes <= 120 ? $minutes : null;

        // Visite encore planifiée alors que son créneau est passé : le
        // bandeau du haut de page propose de la clore (Effectuée / Annulée).
        $overdue = \App\Visit\Domain\VisitStatus::Planned === $visit->status && $minutes < 0;

        // Read model of the property photos: id + display name + phase only,
        // the bytes are streamed by visit_photo_file. Split by phase: the
        // library card shows the listing photos ('before'), the report card
        // shows the ones taken during or after the visit ('after').
        $photos = array_map(
            static fn (\App\Visit\Entity\VisitPhoto $photo): array => [
                'id' => (int) $photo->getId(),
                'originalName' => (string) $photo->getOriginalName(),
                'phase' => $photo->getPhase(),
            ],
            $visits->findOneBy(['reference' => $reference])?->getPhotos()->toArray() ?? [],
        );

        return $this->render('admin/visits/show.html.twig', [
            'adminPrefix' => $adminPrefix,
            'visit' => $visit,
            'minutesUntil' => $minutesUntil,
            'overdue' => $overdue,
            'photosBefore' => array_values(array_filter($photos, static fn (array $p): bool => 'before' === $p['phase'])),
            'photosAfter' => array_values(array_filter($photos, static fn (array $p): bool => 'after' === $p['phase'])),
        ]);
    }

    #[Route(
        path: [
            'fr' => '/visites/{reference}/supprimer',
            'en' => '/visits/{reference}/delete',
        ],
        name: 'visit_delete',
        requirements: ['reference' => 'VS-\d{6}'],
        methods: ['POST'],
    )]
    public function delete(
        string $adminPrefix,
        string $reference,
        Request $request,
        \App\Visit\Repository\VisitRepository $visits,
        \Doctrine\ORM\EntityManagerInterface $em,
        \App\Visit\Storage\VisitPhotoStorage $photoStorage,
        \App\Visit\Service\VisitCalendarSync $calendarSync,
        \Psr\Log\LoggerInterface $logger,
        #[Autowire(service: 'monolog.logger.security')]
        \Psr\Log\LoggerInterface $securityLogger,
    ): Response {
        $this->ensureValidPrefix($adminPrefix);

        if (!$this->isCsrfTokenValid('delete_visit', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $visit = $visits->findOneBy(['reference' => $reference]) ?? throw $this->createNotFoundException();

        // Audit trail: permanent data removal, keep who deleted which visit.
        $securityLogger->notice('Visit deleted', [
            'actor' => $this->getUser()?->getUserIdentifier(),
            'visit' => (int) $visit->getId(),
            'reference' => (string) $visit->getReference(),
        ]);

        // The DB cascade removes the photo rows; the stored objects under
        // visits/<ref>/photos/ must go too (best-effort deletes), and so
        // do both Google Calendar mirror events. A storage outage never
        // blocks the deletion of the visit itself.
        $calendarSync->forget($visit);
        foreach ($visit->getPhotos() as $photo) {
            try {
                $photoStorage->delete((string) $photo->getPath());
            } catch (\Throwable $e) {
                $logger->warning('Visit photo object could not be deleted from storage.', [
                    'visit' => $reference,
                    'path' => (string) $photo->getPath(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $em->remove($visit);
        $em->flush();

        return $this->redirectToRoute('admin_visits', ['adminPrefix' => $adminPrefix], 303);
    }

    #[Route(
        path: [
            'fr' => '/visites/{reference}/statut',
            'en' => '/visits/{reference}/status',
        ],
        name: 'visit_status',
        requirements: ['reference' => 'VS-\d{6}'],
        methods: ['POST'],
    )]
    public function status(string $adminPrefix, string $reference, Request $request, \App\Visit\Repository\VisitRepository $visits, \Doctrine\ORM\EntityManagerInterface $em, \App\Dossier\Service\DossierStatusAdvancer $advancer, \Symfony\Component\Validator\Validator\ValidatorInterface $validator, \App\Visit\Service\VisitCalendarSync $calendarSync, \App\Dossier\Service\DossierEventLogger $events): Response
    {
        $this->ensureValidPrefix($adminPrefix);

        if (!$this->isCsrfTokenValid('visit_status', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $visit = $visits->findOneBy(['reference' => $reference]) ?? throw $this->createNotFoundException();

        $input = new \App\Visit\Domain\VisitStatusInput((string) $request->request->get('status'));
        if ($validator->validate($input)->count() > 0) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Unknown visit status.');
        }

        // The report survives status changes on purpose: it can be written
        // at any point of the visit's life, not only once it is done.
        $previousStatus = $visit->getStatus();
        $visit->setStatus($input->toStatus());
        // Rétrogradation depuis Effectuée : les champs de compte-rendu
        // client (décision, échéance, issue, origine, rappel) n'ont plus de
        // sens pour une visite qui n'a pas eu lieu, ils sont purgés. Le
        // vécu de l'agent (report, ressenti, photos) survit, choix assumé.
        if (\App\Visit\Domain\VisitStatus::Done === $previousStatus
            && \App\Visit\Domain\VisitStatus::Done !== $visit->getStatus()) {
            $visit->setClientDecision(null)
                ->setClientDecisionAt(null)
                ->setApplicationOutcome(null)
                ->setDecisionDeadline(null)
                ->setRefusalOrigin(null)
                ->setDecisionReminderSentAt(null);
        }
        // Fil du dossier : seule la transition vers Effectuée est notée (un
        // re-POST du même statut ne crée pas de doublon).
        if (\App\Visit\Domain\VisitStatus::Done === $visit->getStatus()
            && $previousStatus !== $visit->getStatus()
            && null !== $visit->getDossier()) {
            $events->log($visit->getDossier(), 'visit_done', [
                'value' => trim((string) $visit->getAddress()),
                'date' => $visit->getScheduledAt()?->format('d/m/Y H:i') ?? '',
            ]);
        }
        $this->touchVisit($visit);
        $em->flush();

        // Agenda mirror: a cancellation drops both events, any other status
        // keeps them in step (best-effort, the ids are flushed right after).
        $calendarSync->sync($visit);
        $em->flush();

        // Réaligne le statut du dossier sur ses étapes déjà validées (le
        // calcul d'avancement ne regarde pas les visites : un changement de
        // statut de visite ne valide aucune étape à lui seul).
        $dossier = $visit->getDossier();
        if (null !== $dossier) {
            $advancer->advance($dossier);
        }

        // Une visite passée en Effectuée atterrit sur le formulaire de
        // compte-rendu, qui devient visible avec ce statut.
        $params = ['adminPrefix' => $adminPrefix, 'reference' => $reference];
        if (\App\Visit\Domain\VisitStatus::Done === $visit->getStatus()) {
            $params['_fragment'] = 'visit-report';
        }

        return $this->redirectToRoute('admin_visit_show', $params, 303);
    }

    #[Route(
        path: [
            'fr' => '/visites/{reference}/compte-rendu',
            'en' => '/visits/{reference}/report',
        ],
        name: 'visit_report',
        requirements: ['reference' => 'VS-\d{6}'],
        methods: ['POST'],
    )]
    public function report(string $adminPrefix, string $reference, Request $request, \App\Visit\Repository\VisitRepository $visits, \Doctrine\ORM\EntityManagerInterface $em, \Symfony\Component\Validator\Validator\ValidatorInterface $validator): Response
    {
        $this->ensureValidPrefix($adminPrefix);

        if (!$this->isCsrfTokenValid('visit_report', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $visit = $visits->findOneBy(['reference' => $reference]) ?? throw $this->createNotFoundException();

        $feeling = (string) $request->request->get('feeling');
        $input = new \App\Visit\Domain\VisitReportInput(
            report: trim((string) $request->request->get('report')),
            feeling: '' !== $feeling ? $feeling : null,
        );
        $violations = $validator->validate($input);
        if ($violations->count() > 0) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException((string) $violations);
        }

        $visit->setReport($input->reportOrNull())
            ->setClientFeeling($input->toFeeling());
        $this->touchVisit($visit);
        $em->flush();

        // Confirmation post-redirect : la pile Admin/Toasts consomme le
        // flash "success" (exception assumée au feedback localisé).
        $this->addFlash('success', 'admin.toast.saved');

        // L'autosave recharge la page : on ré-atterrit sur la card
        // compte-rendu, pas en haut de la fiche.
        return $this->redirectToRoute('admin_visit_show', ['adminPrefix' => $adminPrefix, 'reference' => $reference, '_fragment' => 'visit-report'], 303);
    }

    /**
     * Note envoyée au client : enregistrement du texte édité à la main.
     */
    #[Route(
        path: [
            'fr' => '/visites/{reference}/note-client',
            'en' => '/visits/{reference}/client-note',
        ],
        name: 'visit_client_note',
        requirements: ['reference' => 'VS-\d{6}'],
        methods: ['POST'],
    )]
    public function clientNote(string $adminPrefix, string $reference, Request $request, \App\Visit\Repository\VisitRepository $visits, \Doctrine\ORM\EntityManagerInterface $em): Response
    {
        $this->ensureValidPrefix($adminPrefix);

        if (!$this->isCsrfTokenValid('visit_client_note', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $visit = $visits->findOneBy(['reference' => $reference]) ?? throw $this->createNotFoundException();

        $note = trim((string) $request->request->get('clientNote'));
        if (mb_strlen($note) > 5000) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Client note too long.');
        }
        $visit->setClientNote('' !== $note ? $note : null);
        $this->touchVisit($visit);
        $em->flush();

        $this->addFlash('success', 'admin.toast.saved');

        return $this->redirectToRoute('admin_visit_show', ['adminPrefix' => $adminPrefix, 'reference' => $reference, '_fragment' => 'visit-report'], 303);
    }

    /**
     * Envoi de la note client par email à toutes les personnes du dossier
     * (chacune dans sa langue, adresses dédupliquées, best-effort). Le
     * renvoi est permis : la modale de confirmation prévient quand une note
     * est déjà partie. L'horodatage d'envoi survit aux éditions ultérieures
     * de la note.
     */
    #[Route(
        path: [
            'fr' => '/visites/{reference}/note-client/envoyer',
            'en' => '/visits/{reference}/client-note/send',
        ],
        name: 'visit_client_note_send',
        requirements: ['reference' => 'VS-\d{6}'],
        methods: ['POST'],
    )]
    public function clientNoteSend(
        string $adminPrefix,
        string $reference,
        Request $request,
        \App\Visit\Repository\VisitRepository $visits,
        \Doctrine\ORM\EntityManagerInterface $em,
        \App\Visit\Service\VisitClientMailer $mailer,
        \App\Dossier\Service\DossierEventLogger $events,
        \Symfony\Contracts\Translation\TranslatorInterface $translator,
        #[Autowire(service: 'monolog.logger.security')]
        \Psr\Log\LoggerInterface $securityLogger,
    ): Response {
        $this->ensureValidPrefix($adminPrefix);

        if (!$this->isCsrfTokenValid('visit_client_note', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $visit = $visits->findOneBy(['reference' => $reference]) ?? throw $this->createNotFoundException();

        // Garde serveur : le bouton n'existe que pour une note remplie, un
        // POST forgé sur une note vide est refusé.
        if ('' === trim((string) $visit->getClientNote())) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('The client note is empty.');
        }

        ['sent' => $sent, 'total' => $total] = $mailer->sendClientNote($visit);
        if ($sent > 0) {
            $visit->setClientNoteSentAt(new \DateTimeImmutable());
            $this->touchVisit($visit);
            if (null !== $visit->getDossier()) {
                $events->log($visit->getDossier(), 'visit_client_note_sent', [
                    'value' => trim((string) $visit->getAddress()),
                ]);
            }
            $em->flush();

            // Audit trail: outbound client communication, keep who sent it.
            $securityLogger->notice('Visit client note emailed', [
                'actor' => $this->getUser()?->getUserIdentifier(),
                'visit' => (string) $visit->getReference(),
                'recipients' => $sent,
            ]);

            if ($sent < $total) {
                // Envoi partiel (transport en échec pour certains contacts) :
                // le toast dit combien ont réellement reçu la note. Traduit
                // ici car la pile de toasts ne porte pas de paramètres.
                $this->addFlash('success', $translator->trans('admin.visits.show.clientNote.sentPartial', ['%sent%' => $sent, '%total%' => $total]));
            } else {
                $this->addFlash('success', 'admin.toast.visitClientNoteSent');
            }
        } else {
            // Aucun contact joignable (ou transport en échec pour tous) :
            // pas d'horodatage, un toast d'erreur explique pourquoi.
            $this->addFlash('error', 'admin.visits.show.clientNote.sendFailed');
        }

        return $this->redirectToRoute('admin_visit_show', ['adminPrefix' => $adminPrefix, 'reference' => $reference, '_fragment' => 'visit-report'], 303);
    }

    /**
     * Brouillon IA de la note client : généré à la demande depuis les
     * données du bien + le retour interne, persisté puis rouvert en
     * édition. Un échec du modèle retombe en flash, jamais en 500.
     */
    #[Route(
        path: [
            'fr' => '/visites/{reference}/note-client/generer',
            'en' => '/visits/{reference}/client-note/generate',
        ],
        name: 'visit_client_note_generate',
        requirements: ['reference' => 'VS-\d{6}'],
        methods: ['POST'],
    )]
    public function clientNoteGenerate(string $adminPrefix, string $reference, Request $request, \App\Visit\Repository\VisitRepository $visits, \Doctrine\ORM\EntityManagerInterface $em, \App\Visit\Service\VisitClientNoteGenerator $generator): Response
    {
        $this->ensureValidPrefix($adminPrefix);

        if (!$this->isCsrfTokenValid('visit_client_note', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $visit = $visits->findOneBy(['reference' => $reference]) ?? throw $this->createNotFoundException();

        $note = $generator->generate($visit);
        if (null === $note) {
            $this->addFlash('visit_client_note_error', '1');
        } else {
            $visit->setClientNote($note);
            $this->touchVisit($visit);
            $em->flush();
        }

        return $this->redirectToRoute('admin_visit_show', ['adminPrefix' => $adminPrefix, 'reference' => $reference, '_fragment' => 'visit-report'], 303);
    }

    /**
     * "Retour client" du compte-rendu : chips à autosave (un POST par clic,
     * comme les bascules de statut). La chip active renvoie une valeur vide
     * pour se désélectionner (toggle-off).
     */
    #[Route(
        path: [
            'fr' => '/visites/{reference}/retour-client',
            'en' => '/visits/{reference}/client-decision',
        ],
        name: 'visit_client_decision',
        requirements: ['reference' => 'VS-\d{6}'],
        methods: ['POST'],
    )]
    public function clientDecision(string $adminPrefix, string $reference, Request $request, \App\Visit\Repository\VisitRepository $visits, \Doctrine\ORM\EntityManagerInterface $em, \App\Dossier\Service\DossierEventLogger $events): Response
    {
        $this->ensureValidPrefix($adminPrefix);

        if (!$this->isCsrfTokenValid('visit_client_decision', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $visit = $visits->findOneBy(['reference' => $reference]) ?? throw $this->createNotFoundException();

        // Garde serveur : le compte-rendu client n'existe que pour une
        // visite Effectuée (le template ne l'affiche d'ailleurs que dans ce
        // cas), un POST forgé sur un autre statut est refusé.
        if (\App\Visit\Domain\VisitStatus::Done !== $visit->getStatus()) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('The visit is not done.');
        }

        // Même flux pour les trois gestes du bloc : la décision (chips),
        // l'échéance de réflexion (date, seulement en "Réfléchit") et
        // l'origine du refus (chips, seulement en "Refuse"). Le champ posté
        // dit lequel.
        if ($request->request->has('decision')) {
            $raw = (string) $request->request->get('decision');
            $decision = null;
            if ('' !== $raw) {
                $decision = \App\Visit\Domain\ClientDecision::tryFrom($raw)
                    ?? throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Unknown client decision.');
            }

            if ($decision !== $visit->getClientDecision()) {
                // Horodate le changement : nourrit le badge "en attente
                // depuis X jours" du positionnement.
                $visit->setClientDecisionAt(null !== $decision ? new \DateTimeImmutable() : null);
                // Réarmement du rappel d'échéance : un cycle de décision qui
                // quitte puis revient à "Réfléchit" mérite un nouveau rappel.
                $visit->setDecisionReminderSentAt(null);
                // Fil du dossier : seules les transitions marquantes sont
                // notées (jamais un re-POST de la même chip), le refus et le
                // positionnement (la réflexion reste un état d'attente).
                $dossier = $visit->getDossier();
                if (null !== $dossier && \in_array($decision, [\App\Visit\Domain\ClientDecision::Refused, \App\Visit\Domain\ClientDecision::Positioning], true)) {
                    $events->log($dossier, \App\Visit\Domain\ClientDecision::Refused === $decision ? 'visit_client_refused' : 'visit_client_positioned', [
                        'value' => trim((string) $visit->getAddress()),
                    ]);
                }
            }
            $visit->setClientDecision($decision);
            // Les champs de suivi n'ont de sens que dans leur état : tout
            // changement de décision purge les reliquats trompeurs.
            if (\App\Visit\Domain\ClientDecision::Positioning !== $decision) {
                $visit->setApplicationOutcome(null);
            }
            if (\App\Visit\Domain\ClientDecision::Thinking !== $decision) {
                $visit->setDecisionDeadline(null);
            }
            if (\App\Visit\Domain\ClientDecision::Refused !== $decision) {
                $visit->setRefusalOrigin(null);
            }
        } elseif ($request->request->has('deadline')) {
            // Échéance de réflexion : uniquement quand le client réfléchit.
            if (\App\Visit\Domain\ClientDecision::Thinking !== $visit->getClientDecision()) {
                throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('The client is not thinking it over.');
            }
            $raw = trim((string) $request->request->get('deadline'));
            if ('' === $raw) {
                $deadline = null;
            } else {
                $deadline = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
                // Le re-formatage attrape les débordements silencieux de
                // createFromFormat ("2026-02-31" devient le 3 mars) en plus
                // des chaînes illisibles.
                if (false === $deadline || $deadline->format('Y-m-d') !== $raw) {
                    throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Invalid deadline date.');
                }
                // Bornes raisonnables : une échéance de réflexion ne vit ni
                // dans le passé ni au-delà de deux ans.
                // Jour courant (Paris), construit comme la deadline pour une
                // comparaison à fuseau identique.
                $today = \DateTimeImmutable::createFromFormat('!Y-m-d', (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('Y-m-d'));
                \assert(false !== $today);
                if ($deadline < $today || $deadline > $today->modify('+2 years')) {
                    throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Deadline out of bounds.');
                }
            }
            // Une échéance qui change de valeur réarme le rappel staff : la
            // prolongation accordée au client déclenchera un second rappel.
            if ($deadline?->format('Y-m-d') !== $visit->getDecisionDeadline()?->format('Y-m-d')) {
                $visit->setDecisionReminderSentAt(null);
            }
            $visit->setDecisionDeadline($deadline);
        } elseif ($request->request->has('origin')) {
            // Origine du refus : uniquement quand le client refuse.
            if (\App\Visit\Domain\ClientDecision::Refused !== $visit->getClientDecision()) {
                throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('The client has not refused.');
            }
            $raw = (string) $request->request->get('origin');
            $origin = null;
            if ('' !== $raw) {
                $origin = \App\Visit\Domain\RefusalOrigin::tryFrom($raw)
                    ?? throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Unknown refusal origin.');
            }
            // Fil du dossier : l'origine complète l'entrée de refus, notée
            // uniquement sur un vrai changement vers une valeur.
            if (null !== $origin && $origin !== $visit->getRefusalOrigin() && null !== $visit->getDossier()) {
                $events->log($visit->getDossier(), 'visit_refusal_origin', [
                    'value' => trim((string) $visit->getAddress()),
                    'status' => $origin->labelKey(),
                ]);
            }
            $visit->setRefusalOrigin($origin);
        } else {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Nothing to update.');
        }

        $this->touchVisit($visit);
        $em->flush();

        $this->addFlash('success', 'admin.toast.saved');

        return $this->redirectToRoute('admin_visit_show', [
            'adminPrefix' => $adminPrefix,
            'reference' => $reference,
            '_fragment' => 'visit-report',
        ], 303);
    }

    /**
     * Issue de la candidature déposée (décision du bailleur, du propriétaire
     * ou de l'agence) : chips Validé / Refusé à autosave, la chip active se
     * désélectionne en renvoyant une valeur vide (retour "en attente").
     * Uniquement quand le client s'est positionné.
     */
    #[Route(
        path: [
            'fr' => '/visites/{reference}/issue-candidature',
            'en' => '/visits/{reference}/application-outcome',
        ],
        name: 'visit_application_outcome',
        requirements: ['reference' => 'VS-\d{6}'],
        methods: ['POST'],
    )]
    public function applicationOutcome(string $adminPrefix, string $reference, Request $request, \App\Visit\Repository\VisitRepository $visits, \Doctrine\ORM\EntityManagerInterface $em, \App\Dossier\Service\DossierEventLogger $events): Response
    {
        $this->ensureValidPrefix($adminPrefix);

        if (!$this->isCsrfTokenValid('visit_application_outcome', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $visit = $visits->findOneBy(['reference' => $reference]) ?? throw $this->createNotFoundException();

        // Garde serveur : le bloc n'existe que pour une visite Effectuée
        // dont le client s'est positionné, un POST forgé hors contexte est
        // refusé.
        if (\App\Visit\Domain\VisitStatus::Done !== $visit->getStatus()) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('The visit is not done.');
        }
        if (\App\Visit\Domain\ClientDecision::Positioning !== $visit->getClientDecision()) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('The client has not positioned themselves.');
        }

        $raw = (string) $request->request->get('outcome');
        $outcome = null;
        if ('' !== $raw) {
            $outcome = \App\Visit\Domain\ApplicationOutcome::tryFrom($raw)
                ?? throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Unknown application outcome.');
        }

        // Fil du dossier : l'issue de la candidature n'est notée que sur une
        // vraie transition vers Validé / Refusé (le retour "en attente" et
        // les re-POST ne créent pas d'entrée).
        if (null !== $outcome && $outcome !== $visit->getApplicationOutcome() && null !== $visit->getDossier()) {
            $events->log($visit->getDossier(), 'visit_application_outcome', [
                'value' => trim((string) $visit->getAddress()),
                'status' => $outcome->labelKey(),
            ]);
        }
        $visit->setApplicationOutcome($outcome);
        $this->touchVisit($visit);
        $em->flush();

        $this->addFlash('success', 'admin.toast.saved');

        return $this->redirectToRoute('admin_visit_show', [
            'adminPrefix' => $adminPrefix,
            'reference' => $reference,
            '_fragment' => 'visit-report',
        ], 303);
    }

    /**
     * Property photos: plain multipart POST (LiveComponents don't carry
     * files), several files at once. Each valid image is stored in the
     * photo storage (disk or GCS bucket) under visits/<ref>/photos/;
     * invalid files are skipped with a flash, they never block the others.
     */
    #[Route(
        path: [
            'fr' => '/visites/{reference}/photos',
            'en' => '/visits/{reference}/photos',
        ],
        name: 'visit_photos_upload',
        requirements: ['reference' => 'VS-\d{6}'],
        methods: ['POST'],
    )]
    public function uploadPhotos(
        string $adminPrefix,
        string $reference,
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

        $visit = $visits->findOneBy(['reference' => $reference]) ?? throw $this->createNotFoundException();

        // Phase des photos selon le point d'entrée : la bibliothèque (photos
        // de l'annonce) envoie 'before', le bloc compte-rendu envoie 'after'
        // (photos prises pendant ou après la visite).
        $phase = (string) $request->request->get('phase', 'before');
        if (!\in_array($phase, ['before', 'after'], true)) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Unknown photo phase.');
        }

        $rejected = 0;
        // Même plafond cumulé que le formulaire de création : le 12 max ne
        // doit pas exister que côté client (POST forgé).
        $stored = $visit->getPhotos()->count();
        $constraint = new \Symfony\Component\Validator\Constraints\Image(
            maxSize: '10M',
            mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
        );
        foreach ($request->files->all('photos') as $file) {
            if ($stored >= \App\Visit\Twig\Components\VisitForm::MAX_PHOTOS) {
                ++$rejected;
                continue;
            }
            if (!$file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile
                || $validator->validate($file, $constraint)->count() > 0) {
                ++$rejected;
                continue;
            }

            // Un échec de stockage en cours de boucle (réseau, bucket) est
            // compté comme rejet sans bloquer les autres fichiers, comme
            // dans VisitForm::storeUploadedPhotos.
            try {
                $photo = (new \App\Visit\Entity\VisitPhoto())
                    ->setOriginalName((string) $file->getClientOriginalName())
                    ->setMimeType((string) $file->getMimeType())
                    ->setCreatedAt(new \DateTimeImmutable())
                    ->setPhase($phase)
                    ->setPath($storage->store((string) $visit->getReference(), $file));
            } catch (\Throwable) {
                ++$rejected;
                continue;
            }
            $visit->addPhoto($photo);
            ++$stored;
        }
        $this->touchVisit($visit);
        $em->flush();

        if ($rejected > 0) {
            $this->addFlash('visit_photos_rejected', $rejected);
        }

        return $this->redirectToRoute('admin_visit_show', ['adminPrefix' => $adminPrefix, 'reference' => $reference], 303);
    }

    /**
     * Streams a photo from storage (disk or GCS): every read goes through
     * the admin firewall, nothing is directly reachable.
     */
    #[Route(
        path: [
            'fr' => '/visites/{reference}/photos/{photoId}',
            'en' => '/visits/{reference}/photos/{photoId}',
        ],
        name: 'visit_photo_file',
        requirements: ['reference' => 'VS-\d{6}', 'photoId' => '\d+'],
        methods: ['GET'],
    )]
    public function photoFile(
        string $adminPrefix,
        string $reference,
        int $photoId,
        Request $request,
        \App\Visit\Storage\VisitPhotoStorage $storage,
        \Doctrine\ORM\EntityManagerInterface $em,
    ): Response {
        $this->ensureValidPrefix($adminPrefix);

        $photo = $em->find(\App\Visit\Entity\VisitPhoto::class, $photoId);
        if (null === $photo || $photo->getVisit()?->getReference() !== $reference || !$storage->exists((string) $photo->getPath())) {
            throw $this->createNotFoundException();
        }

        $response = new \Symfony\Component\HttpFoundation\StreamedResponse(static function () use ($storage, $photo): void {
            $stream = $storage->readStream((string) $photo->getPath());
            fpassthru($stream);
            fclose($stream);
        });
        $response->headers->set('Content-Type', (string) $photo->getMimeType());
        // ?download=1 : téléchargement sous le nom d'origine (navigation
        // native, pas de fetch+blob — convention du projet).
        if ($request->query->getBoolean('download')) {
            $response->headers->set('Content-Disposition', \Symfony\Component\HttpFoundation\HeaderUtils::makeDisposition(
                \Symfony\Component\HttpFoundation\HeaderUtils::DISPOSITION_ATTACHMENT,
                (string) $photo->getOriginalName(),
                'photo-'.$photoId,
            ));
        }
        $response->headers->set('X-LiteSpeed-Cache-Control', 'no-cache');
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    #[Route(
        path: [
            'fr' => '/visites/{reference}/photos/{photoId}/suppression',
            'en' => '/visits/{reference}/photos/{photoId}/delete',
        ],
        name: 'visit_photo_delete',
        requirements: ['reference' => 'VS-\d{6}', 'photoId' => '\d+'],
        methods: ['POST'],
    )]
    public function deletePhoto(
        string $adminPrefix,
        string $reference,
        int $photoId,
        Request $request,
        \App\Visit\Storage\VisitPhotoStorage $storage,
        \Doctrine\ORM\EntityManagerInterface $em,
        \Psr\Log\LoggerInterface $logger,
        #[Autowire(service: 'monolog.logger.security')]
        \Psr\Log\LoggerInterface $securityLogger,
    ): Response {
        $this->ensureValidPrefix($adminPrefix);

        if (!$this->isCsrfTokenValid('visit_photo_delete', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $photo = $em->find(\App\Visit\Entity\VisitPhoto::class, $photoId);
        if (null === $photo || $photo->getVisit()?->getReference() !== $reference) {
            throw $this->createNotFoundException();
        }

        // Audit trail: permanent file removal, keep who deleted which photo.
        $securityLogger->notice('Visit photo deleted', [
            'actor' => $this->getUser()?->getUserIdentifier(),
            'visit' => $reference,
            'photo' => $photoId,
        ]);

        // Best-effort : une panne du stockage ne bloque pas la suppression
        // métier (l'objet orphelin sera nettoyé par ailleurs).
        try {
            $storage->delete((string) $photo->getPath());
        } catch (\Throwable $e) {
            $logger->warning('Visit photo object could not be deleted from storage.', [
                'visit' => $reference,
                'path' => (string) $photo->getPath(),
                'error' => $e->getMessage(),
            ]);
        }
        $em->remove($photo);
        $this->touchVisit($photo->getVisit());
        $em->flush();

        return $this->redirectToRoute('admin_visit_show', ['adminPrefix' => $adminPrefix, 'reference' => $reference], 303);
    }

    /** Instantané du modificateur (statut, compte-rendu, photos). */
    private function touchVisit(\App\Visit\Entity\Visit $visit): void
    {
        $user = $this->getUser();
        if ($user instanceof \App\Auth\Entity\User) {
            $fullName = trim(($user->getFirstName() ?? '').' '.($user->getLastName() ?? ''));
            $visit->touchBy('' !== $fullName ? $fullName : (string) $user->getEmail(), $user->getAvatarFilename());
        }
    }

    private function ensureValidPrefix(string $adminPrefix): void
    {
        if (!hash_equals($this->adminPathPrefix, $adminPrefix)) {
            throw $this->createNotFoundException();
        }
    }
}
