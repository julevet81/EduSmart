<?php

declare(strict_types=1);

namespace App\Enums;

enum InvoiceStatus: string
{
    case Open = 'open';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Void = 'void';
}
