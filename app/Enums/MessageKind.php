<?php

declare(strict_types=1);

namespace App\Enums;

enum MessageKind: string
{
    case Custom = 'custom';
    case Announcement = 'announcement';
    case AbsenceAlert = 'absence_alert';
    case PaymentReminder = 'payment_reminder';
}
