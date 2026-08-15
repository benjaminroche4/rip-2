<?php

declare(strict_types=1);

namespace App\Dossier\Service;

use App\Dossier\Domain\ContactLanguage;
use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierDocument;
use App\Dossier\Entity\DossierPerson;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Tells the tenant the rental file is complete: every requested piece has
 * been validated, the advisor takes over. Sent once per request cycle when
 * the last piece flips to validated. Recipient resolution mirrors the
 * refusal mailer (person of the triggering piece when reachable, else the
 * uploader, else the first reachable person of the dossier) and the email
 * is written in the recipient's contact language.
 */
final readonly class DossierDocumentCompletionMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /** @return bool whether an email actually left (a code-bearing send re-arms the pairing code) */
    public function send(Dossier $dossier, DossierDocument $document): bool
    {
        $recipient = $this->resolveRecipient($dossier, $document);
        if (null === $recipient) {
            return false;
        }

        $locale = ($recipient->getLanguage() ?? ContactLanguage::FR)->value;
        $fr = 'fr' === $locale;

        $email = (new TemplatedEmail())
            ->from('Contact <contact@relocation-in-paris.fr>')
            ->to((string) $recipient->getEmail())
            ->subject($fr ? 'Votre dossier est complet' : 'Your rental application is complete')
            ->htmlTemplate('emails/dossier_documents_completed.html.twig')
            ->context([
                'fr' => $fr,
                'recipientFirstName' => trim((string) $recipient->getFirstName()),
                'reference' => (string) $dossier->getReference(),
                'depositUrl' => $this->urlGenerator->generate('app_dossier_deposit', [
                    '_locale' => $locale,
                    'code' => (string) $dossier->getPairingCode(),
                ], UrlGeneratorInterface::ABSOLUTE_URL),
                'pairingCode' => (string) $dossier->getPairingCode(),
            ]);

        $this->mailer->send($email);

        return true;
    }

    private function resolveRecipient(Dossier $dossier, DossierDocument $document): ?DossierPerson
    {
        $candidates = [$document->getPerson()];
        foreach ($document->getFiles() as $file) {
            $candidates[] = $file->getUploadedBy();
        }
        foreach ($dossier->getPersons() as $person) {
            $candidates[] = $person;
        }

        foreach ($candidates as $candidate) {
            if (null !== $candidate && '' !== trim((string) $candidate->getEmail())) {
                return $candidate;
            }
        }

        return null;
    }
}
