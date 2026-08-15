<?php

declare(strict_types=1);

namespace App\Dossier\Service;

use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierDocument;
use App\Dossier\Entity\DossierPerson;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Tells the tenant a deposited piece was refused, with the manager's reason
 * when given and the deposit link (pairing code prefilled) to send a new
 * version. Recipient: the person the piece belongs to when reachable, else
 * the person who uploaded the file, else the first reachable person of the
 * dossier; the email is written in the recipient's contact language.
 */
final readonly class DossierDocumentRefusalMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
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

        $locale = $recipient->getLanguage()?->value ?? 'fr';
        $fr = 'fr' === $locale;

        $piece = $this->translator->trans($document->getType()?->labelKey() ?? '', locale: $locale);

        $email = (new TemplatedEmail())
            ->from('Contact <contact@relocation-in-paris.fr>')
            ->to((string) $recipient->getEmail())
            // La pièce concernée dans l'objet : le destinataire sait quoi
            // reprendre sans ouvrir, et l'action attendue est explicite.
            ->subject($fr
                ? \sprintf('Pièce à redéposer : %s', $piece)
                : \sprintf('Document to upload again: %s', $piece))
            ->htmlTemplate('emails/dossier_document_refused.html.twig')
            ->context([
                'fr' => $fr,
                'recipientFirstName' => trim((string) $recipient->getFirstName()),
                'piece' => $piece,
                'personName' => trim(trim((string) $document->getPerson()?->getFirstName()).' '.trim((string) $document->getPerson()?->getLastName())),
                'forSelf' => $recipient === $document->getPerson(),
                'reason' => (string) $document->getRefusalReason(),
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
