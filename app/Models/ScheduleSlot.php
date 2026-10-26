<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Schedule\Time;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Dates/times are plain strings ("Y-m-d", "HH:MM:SS"), deliberately without casts. */
class ScheduleSlot extends Model
{
    use Auditable, BelongsToTenant;

    protected $fillable = [
        'academic_year_id', 'group_id', 'subject_id', 'teacher_id', 'room_id',
        'weekday', 'starts_at', 'ends_at', 'valid_from', 'valid_until', 'is_active',
    ];

    protected function casts(): array
    {
        return ['weekday' => 'integer', 'is_active' => 'boolean'];
    }

    protected function startsAt(): Attribute
    {
        return Attribute::make(set: static fn (string $v): string => Time::normalize($v));
    }

    protected function endsAt(): Attribute
    {
        return Attribute::make(set: static fn (string $v): string => Time::normalize($v));
    }

    /** @return BelongsTo<Group, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /** @return BelongsTo<Subject, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /** @return BelongsTo<Teacher, $this> */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class)->withTrashed();
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}
