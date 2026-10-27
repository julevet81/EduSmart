<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Message */
final class MessageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'channel' => $this->channel->value,
            'kind' => $this->kind->value,
            'recipient' => ['type' => $this->recipient_type, 'id' => $this->recipient_id, 'name' => $this->recipient_name],
            'to' => $this->to,
            'student_id' => $this->student_id,
            'subject' => $this->subject,
            'body' => $this->body,
            'status' => $this->status->value,
            'attempts' => $this->attempts,
            'last_error' => $this->last_error,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'batch_id' => $this->batch_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
