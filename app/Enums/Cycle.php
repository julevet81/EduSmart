<?php

declare(strict_types=1);

namespace App\Enums;

enum Cycle: string
{
    case Preparatory = 'preparatory';
    case Primary = 'primary';
    case Middle = 'middle';
    case Secondary = 'secondary';
    case Other = 'other';
}
