<?php

declare(strict_types=1);

namespace App\Enums;

enum BillingMode: string
{
    case Monthly = 'monthly';
    case Term = 'term';
    case Yearly = 'yearly';
    case OneTime = 'one_time';
    case PerLesson = 'per_lesson';
}
