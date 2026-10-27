<?php

declare(strict_types=1);

namespace App\Enums;

enum MessageChannel: string
{
    case InApp = 'in_app';
    case Sms = 'sms';
    case Whatsapp = 'whatsapp';
    case Email = 'email';
}
