<?php

declare(strict_types=1);

namespace App\Dossier\Service;

use App\Contact\Entity\Contact;
use App\Contact\Entity\ContactNote;
use App\Dossier\Domain\ContactLanguage;
use App\Dossier\Domain\CsvSelection;
use App\Dossier\Domain\DossierPersonRole;
use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierNote;
use App\Dossier\Entity\DossierPerson;
use App\Dossier\Entity\DossierSearch;
use App\Dossier\Repository\DossierRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Turns an admin contact request into a dossier: the contact becomes the
 * primary tenant (identity, email, phone, contact language), the project
 * fields are copied into the dossier's search criteria and the whole
 * follow-up thread (notes) is duplicated. Idempotent on the contact's
 * email — converting twice lands on the same dossier instead of creating
 * a duplicate.
 */
final class ContactDossierConverter
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DossierRepository $repository,
        private readonly DossierNumberGenerator $numbers,
        private readonly DossierDriveProvisioner $drive,
    ) {
    }

    public function convert(Contact $contact): Dossier
    {
        $email = trim((string) $contact->getEmail());

        $existing = '' !== $email ? $this->repository->findByPrimaryTenantEmail($email) : null;
        if (null !== $existing) {
            // Backfill data a previous conversion may not have carried over
            // (search snapshot, follow-up thread, origin reference) without
            // ever overwriting what the dossier already holds.
            if (null === $existing->getSearch()) {
                $existing->setSearch($this->buildSearch($contact));
            }
            if ($existing->getNotes()->isEmpty()) {
                $this->copyNotes($contact, $existing);
            }
            if (null === $existing->getSourceContactReference()) {
                $existing->setSourceContactReference($contact->getReference());
            }
            if (null === $existing->getOffer()) {
                $existing->setOffer($contact->getOffer());
            }
            $this->em->flush();

            return $existing;
        }

        $firstName = trim((string) $contact->getFirstName());
        $lastName = trim((string) $contact->getLastName());

        $person = (new DossierPerson())
            ->setRole(DossierPersonRole::TENANT)
            ->setFirstName(mb_substr($firstName, 0, 50))
            ->setLastName(mb_substr($lastName, 0, 50))
            ->setEmail(mb_substr($email, 0, 180))
            ->setPhone(mb_substr(trim((string) $contact->getPhoneNumber()), 0, 30) ?: null)
            ->setLanguage(ContactLanguage::tryFrom((string) $contact->getLang()) ?? ContactLanguage::FR)
            ->setPrimaryContact(true);

        $dossier = (new Dossier())
            ->setName(mb_substr('' !== $firstName ? $firstName : $lastName, 0, 100) ?: 'Dossier')
            ->setReference($this->numbers->referenceFromContact($contact->getReference()))
            ->setPairingCode($this->numbers->pairingCode())
            // A fresh code is armed: the deposit page refuses it 90 days
            // after the last email embedding it (each send re-arms).
            ->setPairingCodeSentAt(new \DateTimeImmutable())
            ->setCreatedAt(new \DateTimeImmutable())
            ->addPerson($person)
            ->setSearch($this->buildSearch($contact))
            ->setSourceContactReference($contact->getReference())
            ->setOffer($contact->getOffer());

        $this->copyNotes($contact, $dossier);

        $this->em->persist($dossier);
        $this->em->flush();

        // Best-effort: provision the dossier's Shared Drive folder (no-op when
        // Drive is off), so pieces have a home the moment the dossier exists.
        $this->drive->ensureDossierFolder($dossier);

        return $dossier;
    }

    /**
     * Snapshot of the contact's project ("Recherche" seed). Always created,
     * even empty, so the search module starts from the conversion state.
     */
    private function buildSearch(Contact $contact): DossierSearch
    {
        return (new DossierSearch())
            ->setBudget($contact->getProjectBudget())
            ->setAreas($contact->getProjectAreas())
            ->setMoveInAt($contact->getProjectMoveInAt())
            ->setPropertyType($contact->getProjectPropertyType())
            ->setStayDuration($contact->getProjectStayDuration()?->value)
            ->setFurnishing($contact->getProjectFurnishing())
            ->setGuarantorTypes(CsvSelection::values($contact->getProjectGuarantorTypes()))
            ->setNote($contact->getProjectNote());
    }

    /**
     * Duplicates the contact's follow-up thread, oldest first, keeping the
     * original timestamps, denormalised authors and reply structure (a
     * reply stays attached to its parent note, never flattened).
     */
    private function copyNotes(Contact $contact, Dossier $dossier): void
    {
        /** @var list<ContactNote> $notes */
        $notes = $this->em->getRepository(ContactNote::class)->findBy(
            ['contact' => $contact],
            // Id tiebreak: on equal timestamps a parent (lower id) must
            // still be copied before its replies.
            ['createdAt' => 'ASC', 'id' => 'ASC'],
        );

        // Oldest first: a reply's parent is always copied before it.
        $copies = [];
        foreach ($notes as $note) {
            $copy = (new DossierNote())
                ->setText($note->getText())
                ->setCreatedAt($note->getCreatedAt())
                ->setAuthorId($note->getAuthorId())
                ->setAuthorName($note->getAuthorName())
                ->setAuthorAvatar($note->getAuthorAvatar())
                ->setParentNote($copies[$note->getParentNote()?->getId()] ?? null);
            $copies[(int) $note->getId()] = $copy;
            $dossier->addNote($copy);
        }
    }
}
