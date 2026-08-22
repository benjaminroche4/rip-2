<?php

declare(strict_types=1);

namespace App\Contact\Service;

use App\Auth\Entity\User;
use App\Shared\Email\EmailAddress;
use Symfony\Component\Mime\Address;

/**
 * Shared "send as the closer" rules for every client-facing email tied to
 * a staff member (visio invitation, project recap, next-step notice) and
 * for the Google agendas: only an address on the agency Workspace domain
 * can be impersonated by the Calendar delegation and verified by the
 * transactional sender. Off-domain closers fall back to the central
 * address as From, staying reachable through Reply-To; without a closer
 * the central address stands alone.
 */
final class CloserSender
{
    private function __construct()
    {
    }

    /**
     * The staff member's email when it can act as organizer and sender,
     * i.e. a valid address on the agency domain; null otherwise.
     */
    public static function workspaceEmail(?User $closer): ?string
    {
        $email = trim((string) $closer?->getEmail());
        if (false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        $domain = substr((string) strrchr(EmailAddress::CONTACT->value, '@'), 1);

        return str_ends_with(strtolower($email), '@'.$domain) ? $email : null;
    }

    /**
     * From / Reply-To of a client-facing email: the closer's own address
     * and name when it lives on the agency domain (verified sender), the
     * central address otherwise, with the closer kept reachable through
     * Reply-To when their address is off-domain.
     *
     * @return array{from: Address, replyTo: ?Address}
     */
    public static function senderFor(?User $closer): array
    {
        $email = trim((string) $closer?->getEmail());
        if (false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            return ['from' => new Address(EmailAddress::CONTACT->value, 'Relocation in Paris'), 'replyTo' => null];
        }

        $name = trim(((string) $closer?->getFirstName()).' '.((string) $closer?->getLastName()));
        $address = new Address($email, '' !== $name ? $name : $email);
        if (null !== self::workspaceEmail($closer)) {
            return ['from' => $address, 'replyTo' => null];
        }

        // Off-domain closer address: the transactional provider only sends
        // from the verified agency domain, so the closer moves to Reply-To.
        return ['from' => new Address(EmailAddress::CONTACT->value, 'Relocation in Paris'), 'replyTo' => $address];
    }
}
