<?php

declare(strict_types=1);

namespace App\Support\Messaging;

use App\Models\Message;

/** Delivers one message through an external provider. Throw any exception to signal a failure (the message is retried). */
interface ChannelDriver
{
    public function send(Message $message): void;
}
