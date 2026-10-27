<?php

declare(strict_types=1);

namespace App\Support\Messaging;

use App\Models\Message;
use Illuminate\Support\Facades\Log;

/** Development driver: logs metadata only, never the text or the phone number (personal data). */
final class LogDriver implements ChannelDriver
{
    public function send(Message $message): void
    {
        Log::info('message.delivered', [
            'message_id' => $message->getKey(),
            'tenant_id' => $message->tenant_id,
            'channel' => $message->channel->value,
            'length' => mb_strlen($message->body),
        ]);
    }
}
