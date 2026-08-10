<?php

declare(strict_types=1);

namespace App\Contact\Security;

use App\Auth\Entity\User;
use App\Contact\Domain\ContactNoteItem;
use App\Contact\Entity\ContactNote;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * A follow-up note can be edited or deleted only by its author, or by an
 * admin. Accepts both the entity (write path) and the read model (used by
 * templates to decide whether to show the actions).
 *
 * @extends Voter<'CONTACT_NOTE_EDIT'|'CONTACT_NOTE_DELETE', ContactNote|ContactNoteItem>
 */
final class ContactNoteVoter extends Voter
{
    public const EDIT = 'CONTACT_NOTE_EDIT';
    public const DELETE = 'CONTACT_NOTE_DELETE';

    public function __construct(
        private readonly Security $security,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::EDIT, self::DELETE], true)
            && ($subject instanceof ContactNote || $subject instanceof ContactNoteItem);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        // Being the author is not enough: a member moved out of the
        // contacts section must not keep editing their old notes.
        if (!$this->security->isGranted('ROLE_SECTION_CONTACTS')) {
            return false;
        }

        if ($this->security->isGranted('ROLE_ADMIN')) {
            return true;
        }

        $authorId = $subject instanceof ContactNote ? $subject->getAuthorId() : $subject->authorId;

        return $authorId === (int) $user->getId();
    }
}
