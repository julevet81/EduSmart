<?php

declare(strict_types=1);

namespace App\Enums;

enum EnrollmentStatus: string
{
    case Active = 'active';
    case Transferred = 'transferred';
    case Withdrawn = 'withdrawn';
    case Completed = 'completed';

    /** Statuses an active enrollment can be moved to (re-activation goes through a new enrollment). */
    public static function closing(): array
    {
        return [self::Transferred->value, self::Withdrawn->value, self::Completed->value];
    }
}
