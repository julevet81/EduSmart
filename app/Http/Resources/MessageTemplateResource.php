<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\MessageTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MessageTemplate */
final class MessageTemplateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'key' => $this->key, 'name' => $this->name, 'channel' => $this->channel?->value,
            'subject' => $this->subject, 'body' => $this->body, 'is_active' => $this->is_active,
        ];
    }
}
