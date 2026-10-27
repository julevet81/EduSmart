<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Enums\MessageChannel;
use App\Http\Resources\MessageTemplateResource;
use App\Models\MessageTemplate;
use App\Support\Messaging\Placeholders;
use App\Support\Validation\TenantRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Reusable texts with {placeholders}. Keys "absence_alert" and "payment_reminder" override the built-in alert texts. */
final class MessageTemplateController extends ResourceController
{
    protected function model(): string
    {
        return MessageTemplate::class;
    }

    protected function resource(): string
    {
        return MessageTemplateResource::class;
    }

    protected function rules(Request $request, ?Model $model): array
    {
        $immutable = $model === null
            ? ['key' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9_]+$/', TenantRules::unique('message_templates', 'key')]]
            : [];

        return $immutable + [
            'name' => ['required', 'string', 'max:100'],
            'channel' => ['nullable', Rule::enum(MessageChannel::class)],
            'subject' => ['nullable', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ];
    }

    protected function persist(array $data, ?Model $model): Model
    {
        $unknown = array_merge(Placeholders::unknown((string) ($data['body'] ?? '')), Placeholders::unknown((string) ($data['subject'] ?? '')));

        if ($unknown !== []) {
            abort(422, 'Unknown placeholder(s): {'.implode('}, {', array_unique($unknown)).'}. Allowed: {'.implode('}, {', Placeholders::ALLOWED).'}.');
        }

        return parent::persist($data, $model);
    }
}
