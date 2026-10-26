<?php

declare(strict_types=1);

namespace App\Enums;

enum LessonStatus: string
{
    case Scheduled = 'scheduled';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
