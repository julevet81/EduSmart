<?php

declare(strict_types=1);

namespace App\Enums;

enum PayslipStatus: string
{
    case Draft = 'draft';
    case Paid = 'paid';
    case Void = 'void';
    case Reversed = 'reversed';
}
