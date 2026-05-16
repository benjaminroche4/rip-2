<?php

declare(strict_types=1);

namespace App\Auth\Attribute;

/**
 * Opt-out marker for routes that must stay reachable to a logged-in user whose
 * profile is still incomplete (typically a fresh Google sign-in that hasn't yet
 * provided phone / nationality / situation / terms consent).
 *
 * Without this attribute, ProfileCompletionListener force-redirects every request
 * to app_register_complete. Routes that need to bypass the gate (the completion
 * page itself, avatar serving, logout) declare this attribute at class or method
 * level.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final class AllowIncompleteProfile
{
}
