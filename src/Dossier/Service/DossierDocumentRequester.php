<?php

declare(strict_types=1);

namespace App\Dossier\Service;

use App\Dossier\Domain\DossierDocumentStatus;
use App\Dossier\Domain\DossierDocumentType;
use App\Dossier\Domain\PersonName;
use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierDocument;
use App\Dossier\Entity\DossierPerson;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Outbound side of the file module: creates the requested piece rows and
 * sends the request email, or re-sends a reminder for the pieces still
 * awaiting a deposit. Audit trail included; live re-render events stay in
 * the calling component.
 */
final class DossierDocumentRequester
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        private readonly DossierDocumentRequestMailer $mailer,
        private readonly DossierEventLogger $events,
        private readonly DossierStatusAdvancer $advancer,
    ) {
    }

    /**
     * Creates the document rows and sends the request email.
     *
     * @param list<DossierDocumentType> $types
     */
    public function request(Dossier $dossier, DossierPerson $tenant, DossierPerson $recipient, array $types): void
    {
        $now = $this->clock->now();
        foreach ($types as $type) {
            $tenant->addDocument((new DossierDocument())
                ->setType($type)
                ->setStatus(DossierDocumentStatus::Requested)
                ->setRequestedAt($now));
        }
        $this->events->log($dossier, 'documents_requested', [
            'tenant' => PersonName::firstLast($tenant),
            'recipient' => (string) $recipient->getEmail(),
            'pieces' => array_map(static fn (DossierDocumentType $type): string => $type->labelKey(), $types),
        ]);
        $this->em->flush();
        $this->advancer->advance($dossier);

        $this->mailer->send($dossier, $tenant, $recipient, $types);
        // The email embeds the pairing code: every successful send re-arms
        // its sliding 90-day expiry window on the public deposit page.
        $dossier->setPairingCodeSentAt($this->clock->now());
        $this->em->flush();
    }

    /**
     * Sends the reminder to every recipient. Every email that actually left
     * must land in the audit trail, even when a later recipient fails: send
     * per recipient, log what went out, then surface the failure.
     *
     * @param list<DossierPerson>       $recipients
     * @param list<DossierDocumentType> $types
     *
     * @return array{sent: list<string>, failed: bool}
     */
    public function resend(Dossier $dossier, DossierPerson $tenant, array $recipients, array $types): array
    {
        $sent = [];
        $failed = false;
        foreach ($recipients as $recipient) {
            try {
                $this->mailer->send($dossier, $tenant, $recipient, $types);
                $sent[] = (string) $recipient->getEmail();
            } catch (\Throwable) {
                $failed = true;
            }
        }
        if ([] !== $sent) {
            // At least one reminder embedding the pairing code left: re-arm
            // its sliding 90-day expiry window on the public deposit page.
            $dossier->setPairingCodeSentAt($this->clock->now());
            $this->events->log($dossier, 'documents_resent', [
                'tenant' => PersonName::firstLast($tenant),
                'recipient' => implode(', ', $sent),
                'pieces' => array_map(static fn (DossierDocumentType $type): string => $type->labelKey(), $types),
            ]);
            $this->em->flush();
        }

        return ['sent' => $sent, 'failed' => $failed];
    }
}
