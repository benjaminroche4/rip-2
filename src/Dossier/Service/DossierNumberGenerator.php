<?php

declare(strict_types=1);

namespace App\Dossier\Service;

use App\Dossier\Repository\DossierRepository;
use Psr\Log\LoggerInterface;

/**
 * Random public identifiers for dossiers: the dossier number ("DS-087526")
 * and the pairing code ("ABE78L"). Uniqueness is checked against the DB
 * before returning; the unique indexes remain the final guard against the
 * (negligible) race between check and flush.
 */
final class DossierNumberGenerator
{
    /** Letters + digits without the O/0 and I/1 lookalikes. */
    private const PAIRING_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const PAIRING_LENGTH = 6;

    public function __construct(
        private readonly DossierRepository $repository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function reference(): string
    {
        do {
            $reference = \sprintf('DS-%06d', random_int(0, 999999));
        } while (null !== $this->repository->findOneBy(['reference' => $reference]));

        return $reference;
    }

    /**
     * Reference of a dossier created by converting a lead: the six digits of
     * the contact reference carry over ("CT-123456" becomes "DS-123456") so
     * both records read as one file. On a collision (a dossier already owns
     * those digits) the random generation takes over, with a warning logged.
     */
    public function referenceFromContact(string $contactReference): string
    {
        if (1 === preg_match('/^CT-(\d{6})$/', $contactReference, $matches)) {
            $reference = 'DS-'.$matches[1];
            if (null === $this->repository->findOneBy(['reference' => $reference])) {
                return $reference;
            }

            $this->logger->warning('Dossier reference {reference} already taken, falling back to a random one.', [
                'reference' => $reference,
                'contact' => $contactReference,
            ]);
        }

        return $this->reference();
    }

    public function pairingCode(): string
    {
        $max = \strlen(self::PAIRING_ALPHABET) - 1;

        do {
            $code = '';
            for ($i = 0; $i < self::PAIRING_LENGTH; ++$i) {
                $code .= self::PAIRING_ALPHABET[random_int(0, $max)];
            }
        } while (null !== $this->repository->findOneBy(['pairingCode' => $code]));

        return $code;
    }
}
