<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MessageChannel;
use App\Enums\MessageKind;
use App\Enums\MessageStatus;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Outbox row of every channel and, for channel in_app, the recipient's inbox.
 * Not audited row by row (high volume, and the text is personal data): sends are audited once per batch.
 */
class Message extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'channel', 'kind', 'recipient_type', 'recipient_id', 'recipient_name', 'to', 'student_id', 'subject', 'body',
        'status', 'attempts', 'last_error', 'sent_at', 'read_at', 'dedupe_key', 'batch_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'channel' => MessageChannel::class,
            'kind' => MessageKind::class,
            'status' => MessageStatus::class,
            'attempts' => 'integer',
            'sent_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }
}
