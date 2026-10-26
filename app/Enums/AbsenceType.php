<?php

declare(strict_types=1);

namespace App\Enums;

enum AbsenceType: string
{
    case Absent = 'absent';
    case Late = 'late';
}
