<?php

declare(strict_types=1);

namespace App\Enums;

enum TenantStatus: string
{
    case Trial = 'trial';
    case Active = 'active';
    /** Coverage ended, inside the grace period: everything still works. */
    case PastDue = 'past_due';
    /** Coverage and grace ended: data stays readable, writes are refused until renewal. */
    case ReadOnly = 'read_only';
    case Suspended = 'suspended';
    case Archived = 'archived';

    /** Whether people may sign in at all (read-only is enforced per request, see ResolveTenant). */
    public function allowsAccess(): bool
    {
        return match ($this) {
            self::Trial, self::Active, self::PastDue, self::ReadOnly => true,
            self::Suspended, self::Archived => false,
        };
    }
}
