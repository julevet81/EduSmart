<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubjectCoefficient extends Model
{
    use Auditable, BelongsToTenant;

    protected $fillable = ['level_id', 'track_id', 'subject_id', 'coefficient'];

    protected function casts(): array
    {
        return ['coefficient' => 'integer'];
    }

    protected static function booted(): void
    {
        // NULL track_id cannot be part of a unique index in MySQL: track_key (0 = whole level) carries it.
        static::saving(static function (SubjectCoefficient $c): void {
            $c->track_key = (int) ($c->track_id ?? 0);
        });
    }

    /** @return BelongsTo<Subject, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }
}
