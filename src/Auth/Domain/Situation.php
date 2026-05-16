<?php

declare(strict_types=1);

namespace App\Auth\Domain;

/**
 * Professional situation captured during registration step 2 — used to tailor
 * marketing communications and unlock context-specific support flows later
 * (employee relocation paperwork vs. student visa support, for instance).
 *
 * String-backed with the full translation key as value so Doctrine persists
 * the i18n key directly and templates can render it via `{{ user.situation.value|trans }}`.
 */
enum Situation: string
{
    case Employee = 'register.form.situation.choice.employee';
    case Freelance = 'register.form.situation.choice.freelance';
    case Entrepreneur = 'register.form.situation.choice.entrepreneur';
    case Student = 'register.form.situation.choice.student';
    case Retired = 'register.form.situation.choice.retired';
    case Other = 'register.form.situation.choice.other';
}
