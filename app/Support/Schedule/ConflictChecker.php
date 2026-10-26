<?php

declare(strict_types=1);

namespace App\Support\Schedule;

use App\Models\Lesson;
use App\Models\ScheduleSlot;
use Illuminate\Database\Eloquent\Builder;

/**
 * Finds timetable clashes for the three resources that cannot be in two places at once:
 * the teacher, the group and the room. Touching intervals (09:30-10:00 after 08:00-09:30) are allowed.
 * Returns validation-style errors keyed by the offending field; empty array = no conflict.
 */
final class ConflictChecker
{
    private const FIELDS = ['teacher_id' => 'teacher', 'group_id' => 'group', 'room_id' => 'room'];

    /**
     * @param  array<string, mixed>  $slot  weekday, starts_at, ends_at (HH:MM:SS), valid_from, valid_until + resource ids
     * @return array<string, list<string>>
     */
    public function forSlot(array $slot, ?int $ignoreSlotId = null): array
    {
        $query = ScheduleSlot::query()
            ->where('is_active', true)
            ->where('weekday', $slot['weekday'])
            ->where('starts_at', '<', $slot['ends_at'])
            ->where('ends_at', '>', $slot['starts_at'])
            ->where('valid_from', '<=', $slot['valid_until'])
            ->where('valid_until', '>=', $slot['valid_from'])
            ->when($ignoreSlotId !== null, fn (Builder $q) => $q->whereKeyNot($ignoreSlotId));

        return $this->collect($query, $slot);
    }

    /**
     * @param  array<string, mixed>  $lesson  date, starts_at, ends_at (HH:MM:SS) + resource ids
     * @return array<string, list<string>>
     */
    public function forLesson(array $lesson, ?int $ignoreLessonId = null): array
    {
        $query = Lesson::query()
            ->where('date', $lesson['date'])
            ->where('status', '!=', 'cancelled')
            ->where('starts_at', '<', $lesson['ends_at'])
            ->where('ends_at', '>', $lesson['starts_at'])
            ->when($ignoreLessonId !== null, fn (Builder $q) => $q->whereKeyNot($ignoreLessonId));

        return $this->collect($query, $lesson);
    }

    /**
     * @param  Builder<ScheduleSlot>|Builder<Lesson>  $base
     * @param  array<string, mixed>  $attrs
     * @return array<string, list<string>>
     */
    private function collect(Builder $base, array $attrs): array
    {
        $errors = [];

        foreach (self::FIELDS as $field => $label) {
            if (empty($attrs[$field])) {
                continue;
            }

            $hit = (clone $base)->where($field, $attrs[$field])->first(['id', 'starts_at', 'ends_at']);

            if ($hit !== null) {
                $errors[$field] = [sprintf(
                    'The %s is already booked from %s to %s.',
                    $label, Time::short($hit->starts_at), Time::short($hit->ends_at),
                )];
            }
        }

        return $errors;
    }
}
