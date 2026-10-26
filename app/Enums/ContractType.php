<?php

declare(strict_types=1);

namespace App\Enums;

enum ContractType: string
{
    case Monthly = 'monthly';
    case PerHour = 'per_hour';
    case PerLesson = 'per_lesson';
}
