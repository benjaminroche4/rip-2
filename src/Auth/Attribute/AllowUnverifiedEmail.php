<?php

declare(strict_types=1);

namespace App\Auth\Attribute;

/**
 * Opt-out marker for routes that must stay reachable to a logged-in user whose
 * email is not yet verified (classic sign-up that hasn't typed the 6-digit OTP).
 *
 * Without this attribute, EmailVerificationListener force-redirects every request
 * to app_register_verify_code. Routes that need to bypass the gate (the verify
 * page itself, the resend code action, avatar serving, complete-profile while
 * the profile gate still applies, etc.) declare this attribute at class or
 * method level.
 *
 * Mirror of {@see AllowIncompleteProfile} — the two gates layer (profile first,
 * then email verification). If both flags are unset, the profile-completion
 * gate wins so the verify gate never runs on an incomplete user.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final class AllowUnverifiedEmail
{
}
