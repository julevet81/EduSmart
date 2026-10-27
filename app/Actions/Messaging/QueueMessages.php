<?php

declare(strict_types=1);

namespace App\Actions\Messaging;

use App\Enums\MessageChannel;
use App\Enums\MessageKind;
use App\Enums\MessageStatus;
use App\Models\Message;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Messaging\Placeholders;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;

/**
 * Writes the messages of one batch. in_app messages are delivered at once (they ARE the inbox row);
 * the other channels are queued for the dispatcher. A recipient carrying a dedupe_key that already exists is skipped
 * (the unique index makes this safe under concurrency).
 */
final class QueueMessages
{
    /**
     * @param  list<array<string, mixed>>  $recipients  type, id, name, to, student_id, vars, dedupe_key?
     * @return array{batch_id: string, queued: int, duplicates: int}
     */
    public function handle(MessageChannel $channel, MessageKind $kind, array $recipients, ?string $subject, string $body, ?User $actor): array
    {
        $tenant = app(TenantContext::class)->require();
        $batch = (string) Str::uuid();
        $now = now();
        $inApp = $channel === MessageChannel::InApp;
        $inserted = 0;

        foreach (array_chunk($recipients, 500) as $chunk) {
            $rows = array_map(function (array $r) use ($tenant, $channel, $kind, $subject, $body, $actor, $batch, $now, $inApp): array {
                $vars = ($r['vars'] ?? []) + ['institution' => $tenant->name, 'date' => today()->toDateString()];

                return [
                    'tenant_id' => $tenant->getKey(),
                    'channel' => $channel->value,
                    'kind' => $kind->value,
                    'recipient_type' => $r['type'],
                    'recipient_id' => $r['id'],
                    'recipient_name' => $r['name'] ?? null,
                    'to' => $r['to'] ?? null,
                    'student_id' => $r['student_id'] ?? null,
                    'subject' => $subject === null ? null : Placeholders::render($subject, $vars),
                    'body' => Placeholders::render($body, $vars),
                    'status' => ($inApp ? MessageStatus::Sent : MessageStatus::Queued)->value,
                    'attempts' => 0,
                    'sent_at' => $inApp ? $now : null,
                    'dedupe_key' => $r['dedupe_key'] ?? null,
                    'batch_id' => $batch,
                    'created_by' => $actor?->getKey(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }, $chunk);

            $inserted += Message::query()->insertOrIgnore($rows);
        }

        if ($inserted > 0) {
            AuditRecorder::event('message.batch_queued', [
                'batch_id' => $batch, 'channel' => $channel->value, 'kind' => $kind->value, 'count' => $inserted,
            ], (int) $tenant->getKey());
        }

        return ['batch_id' => $batch, 'queued' => $inserted, 'duplicates' => count($recipients) - $inserted];
    }
}
