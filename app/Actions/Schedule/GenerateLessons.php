<?php

declare(strict_types=1);

namespace App\Actions\Schedule;

use App\Enums\LessonKind;
use App\Enums\LessonStatus;
use App\Models\Holiday;
use App\Models\Lesson;
use App\Models\ScheduleSlot;
use App\Support\Audit\AuditRecorder;
use App\Support\Schedule\ScheduleLock;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Turns the recurring slots into concrete lessons for a short window. Idempotent: running it twice
 * creates nothing new (unique slot+date), and a lesson that was cancelled is never recreated.
 * Skips holidays, and skips (and reports) any occurrence that would clash with an existing lesson
 * (typically a manually moved or make-up lesson).
 */
final class GenerateLessons
{
    private const CHUNK = 500;
    private const MAX_REPORTED_CONFLICTS = 50;

    public function __construct(private readonly TenantContext $context) {}

    /** @return array{created: int, skipped_holidays: int, skipped_existing: int, skipped_conflicts: list<array<string, mixed>>} */
    public function handle(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $tenantId = (int) $this->context->require()->getKey();
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();

        return DB::transaction(function () use ($tenantId, $from, $to, $fromDate, $toDate): array {
            ScheduleLock::acquire();

            /** @var array<int, list<ScheduleSlot>> $slotsByWeekday */
            $slotsByWeekday = ScheduleSlot::query()
                ->where('is_active', true)
                ->where('valid_from', '<=', $toDate)
                ->where('valid_until', '>=', $fromDate)
                ->get()
                ->groupBy('weekday')
                ->map(fn ($g) => $g->all())
                ->all();

            $holidays = Holiday::query()
                ->where('starts_on', '<=', $toDate)->where('ends_on', '>=', $fromDate)
                ->get(['starts_on', 'ends_on']);

            $existing = Lesson::query()
                ->whereBetween('date', [$fromDate, $toDate])
                ->get(['id', 'schedule_slot_id', 'date', 'starts_at', 'ends_at', 'teacher_id', 'room_id', 'group_id', 'status']);

            $have = [];
            $busy = [];
            foreach ($existing as $lesson) {
                $have[$lesson->schedule_slot_id.'|'.$lesson->date] = true;
                if ($lesson->status !== LessonStatus::Cancelled) {
                    $busy[$lesson->date][] = $lesson->only(['starts_at', 'ends_at', 'teacher_id', 'room_id', 'group_id']);
                }
            }

            $rows = [];
            $skippedHolidays = $skippedExisting = 0;
            $conflicts = [];
            $now = now();

            for ($day = $from; $day->lte($to); $day = $day->addDay()) {
                $date = $day->toDateString();

                foreach ($slotsByWeekday[$day->dayOfWeek] ?? [] as $slot) {
                    if ($date < $slot->valid_from || $date > $slot->valid_until) {
                        continue;
                    }

                    if ($holidays->contains(fn (Holiday $h): bool => $date >= $h->starts_on && $date <= $h->ends_on)) {
                        $skippedHolidays++;

                        continue;
                    }

                    if (isset($have[$slot->getKey().'|'.$date])) {
                        $skippedExisting++;

                        continue;
                    }

                    $clash = $this->clash($busy[$date] ?? [], $slot);
                    if ($clash !== []) {
                        if (count($conflicts) < self::MAX_REPORTED_CONFLICTS) {
                            $conflicts[] = ['date' => $date, 'schedule_slot_id' => $slot->getKey(), 'fields' => $clash];
                        }

                        continue;
                    }

                    $busy[$date][] = $slot->only(['starts_at', 'ends_at', 'teacher_id', 'room_id', 'group_id']);
                    $rows[] = [
                        'tenant_id' => $tenantId,
                        'schedule_slot_id' => $slot->getKey(),
                        'academic_year_id' => $slot->academic_year_id,
                        'group_id' => $slot->group_id,
                        'subject_id' => $slot->subject_id,
                        'teacher_id' => $slot->teacher_id,
                        'room_id' => $slot->room_id,
                        'kind' => LessonKind::Regular->value,
                        'date' => $date,
                        'starts_at' => $slot->starts_at,
                        'ends_at' => $slot->ends_at,
                        'status' => LessonStatus::Scheduled->value,
                        'is_customized' => false,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            $created = 0;
            foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                $created += DB::table('lessons')->insertOrIgnore($chunk);
            }

            AuditRecorder::event('lessons.generated', [
                'from' => $fromDate, 'to' => $toDate, 'created' => $created,
                'skipped_holidays' => $skippedHolidays, 'skipped_conflicts' => count($conflicts),
            ]);

            return [
                'created' => $created,
                'skipped_holidays' => $skippedHolidays,
                'skipped_existing' => $skippedExisting,
                'skipped_conflicts' => $conflicts,
            ];
        });
    }

    /**
     * @param  list<array<string, mixed>>  $busy  lessons already planned on that date
     * @return list<string> the clashing resources ("teacher_id", "room_id", "group_id")
     */
    private function clash(array $busy, ScheduleSlot $slot): array
    {
        $fields = [];

        foreach ($busy as $other) {
            if ($other['starts_at'] >= $slot->ends_at || $other['ends_at'] <= $slot->starts_at) {
                continue;
            }

            foreach (['teacher_id', 'group_id', 'room_id'] as $field) {
                if ($slot->{$field} !== null && (int) $other[$field] === (int) $slot->{$field}) {
                    $fields[$field] = $field;
                }
            }
        }

        return array_values($fields);
    }
}
