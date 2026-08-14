<?php

declare(strict_types=1);

namespace App\Dossier\Service;

use App\Dossier\Domain\DossierDocumentType;
use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierPerson;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Sends the "pieces to provide" request of the file module to the chosen
 * dossier person, in their contact language, with a link to the public
 * deposit page (pairing code prefilled; the recipient completes with their
 * email, which alone is not enough to get in).
 */
final readonly class DossierDocumentRequestMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @param list<DossierDocumentType> $types
     */
    public function send(Dossier $dossier, DossierPerson $tenant, DossierPerson $recipient, array $types): void
    {
        $locale = $recipient->getLanguage()?->value ?? 'fr';
        $fr = 'fr' === $locale;

        $pieces = array_map(
            fn (DossierDocumentType $type): string => $this->translator->trans($type->labelKey(), locale: $locale),
            $types,
        );

        $email = (new TemplatedEmail())
            ->from('Contact <contact@relocation-in-paris.fr>')
            ->to((string) $recipient->getEmail())
            // Le nombre de pièces en tête : le destinataire sait ce qui
            // l'attend avant d'ouvrir, et le suffixe de marque disparaît (il
            // répétait l'expéditeur en mangeant l'aperçu mobile).
            ->subject($this->subject($fr, \count($pieces)))
            ->htmlTemplate('emails/dossier_documents_request.html.twig')
            ->context([
                'fr' => $fr,
                'recipientFirstName' => trim((string) $recipient->getFirstName()),
                'tenantName' => trim(trim((string) $tenant->getFirstName()).' '.trim((string) $tenant->getLastName())),
                'forSelf' => $recipient === $tenant,
                'pieces' => $pieces,
                'reference' => (string) $dossier->getReference(),
                'depositUrl' => $this->urlGenerator->generate('app_dossier_deposit', [
                    '_locale' => $locale,
                    'code' => (string) $dossier->getPairingCode(),
                ], UrlGeneratorInterface::ABSOLUTE_URL),
                'pairingCode' => (string) $dossier->getPairingCode(),
            ]);

        $this->mailer->send($email);
    }

    private function subject(bool $fr, int $count): string
    {
        if ($fr) {
            return 1 === $count
                ? 'Une pièce à fournir pour votre dossier locatif'
                : \sprintf('%d pièces à fournir pour votre dossier locatif', $count);
        }

        return 1 === $count
            ? 'One document to provide for your rental application'
            : \sprintf('%d documents to provide for your rental application', $count);
    }
}
