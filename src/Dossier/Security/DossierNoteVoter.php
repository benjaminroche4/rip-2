<?php

declare(strict_types=1);

namespace App\Dossier\Security;

use App\Auth\Entity\User;
use App\Dossier\Domain\DossierNoteView;
use App\Dossier\Entity\DossierNote;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Per-note permissions on the dossier follow-up thread: the author edits and
 * deletes their own notes, admins can act on any note. Mirrors
 * ContactNoteVoter.
 *
 * @extends Voter<string, DossierNote|DossierNoteView>
 */
final class DossierNoteVoter extends Voter
{
    public const EDIT = 'DOSSIER_NOTE_EDIT';
    public const DELETE = 'DOSSIER_NOTE_DELETE';

    public function __construct(
        private readonly Security $security,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::EDIT, self::DELETE], true)
            && ($subject instanceof DossierNote || $subject instanceof DossierNoteView);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        if ($this->security->isGranted('ROLE_ADMIN')) {
            return true;
        }

        $authorId = $subject instanceof DossierNote ? $subject->getAuthorId() : $subject->authorId;

        return $authorId === (int) $user->getId();
    }
}
