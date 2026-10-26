<?php

declare(strict_types=1);

namespace App\Enums;

enum AdjustmentKind: string
{
    case Advance = 'advance';
    case Deduction = 'deduction';
    case Bonus = 'bonus';
}
