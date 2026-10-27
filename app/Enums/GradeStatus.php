<?php

declare(strict_types=1);

namespace App\Enums;

enum GradeStatus: string
{
    case Present = 'present';
    case Absent = 'absent';
}
