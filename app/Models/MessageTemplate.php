<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MessageChannel;
use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class MessageTemplate extends Model
{
    use Auditable, BelongsToTenant;

    protected $fillable = ['key', 'name', 'channel', 'subject', 'body', 'is_active'];

    protected function casts(): array
    {
        return ['channel' => MessageChannel::class, 'is_active' => 'boolean'];
    }
}
