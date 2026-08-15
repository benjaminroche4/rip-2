<?php

declare(strict_types=1);

namespace App\Shared\Notes;

use App\Auth\Entity\User;

/** Staff member signing a follow-up note (contact or dossier thread). */
final readonly class NoteAuthor
{
    public function __construct(
        public int $id,
        public string $displayName,
        public ?string $avatarFilename,
    ) {
    }

    public static function fromUser(User $user): self
    {
        $fullName = trim(($user->getFirstName() ?? '').' '.($user->getLastName() ?? ''));

        return new self(
            id: (int) $user->getId(),
            displayName: '' !== $fullName ? $fullName : (string) $user->getEmail(),
            avatarFilename: $user->getAvatarFilename(),
        );
    }
}
