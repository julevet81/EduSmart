<?php

declare(strict_types=1);

namespace App\Support\Messaging;

use App\Models\Message;

final class NullDriver implements ChannelDriver
{
    public function send(Message $message): void {}
}
