<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LessonKind;
use App\Enums\LessonStatus;
use App\Support\Audit\Auditable;
use App\Support\Schedule\Time;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** "date" is a plain "Y-m-d" string and times are "HH:MM:SS" strings, deliberately without casts. */
class Lesson extends Model
{
    use Auditable, BelongsToTenant;

    protected $fillable = [
        'schedule_slot_id', 'academic_year_id', 'group_id', 'subject_id', 'teacher_id', 'room_id',
        'kind', 'date', 'starts_at', 'ends_at', 'status', 'is_customized', 'note', 'completed_at', 'completed_by',
    ];

    protected function casts(): array
    {
        return [
            'kind' => LessonKind::class,
            'status' => LessonStatus::class,
            'is_customized' => 'boolean',
            'completed_at' => 'datetime',
        ];
    }

    protected function startsAt(): Attribute
    {
        return Attribute::make(set: static fn (string $v): string => Time::normalize($v));
    }

    protected function endsAt(): Attribute
    {
        return Attribute::make(set: static fn (string $v): string => Time::normalize($v));
    }

    /** Removes the future, untouched, generated lessons of a slot (after the slot changed or was deleted). */
    public static function purgeGeneratedFor(int $slotId): int
    {
        return static::query()
            ->where('schedule_slot_id', $slotId)
            ->where('date', '>=', today()->toDateString())
            ->where('status', LessonStatus::Scheduled->value)
            ->where('is_customized', false)
            ->delete();
    }

    /**
     * Students enrolled in the lesson's group ON the lesson date (history-aware: transfers and withdrawals count).
     *
     * @return Builder<Enrollment>
     */
    public function enrolledStudentIds(): Builder
    {
        return Enrollment::query()
            ->select('student_id')
            ->where('group_id', $this->group_id)
            ->whereDate('enrolled_on', '<=', $this->date)
            ->where(fn (Builder $q) => $q->whereNull('ended_on')->orWhereDate('ended_on', '>=', $this->date));
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

    /** @return HasMany<Absence, $this> */
    public function absences(): HasMany
    {
        return $this->hasMany(Absence::class);
    }
}
