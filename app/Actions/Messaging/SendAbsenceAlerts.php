<?php

declare(strict_types=1);

namespace App\Actions\Messaging;

use App\Enums\AbsenceType;
use App\Enums\MessageChannel;
use App\Enums\MessageKind;
use App\Models\Absence;
use App\Models\User;
use App\Support\Messaging\AudienceResolver;
use App\Support\Messaging\DefaultTemplates;

/**
 * Tells the guardians of every student with at least $threshold UNJUSTIFIED absences in the last $days days.
 * At most one alert per (guardian, student, ISO week): running it daily does not spam.
 */
final class SendAbsenceAlerts
{
    public function __construct(private readonly AudienceResolver $audience, private readonly QueueMessages $queue) {}

    /** @return array{students: int, queued: int, duplicates: int, skipped: int} */
    public function handle(int $days, int $threshold, MessageChannel $channel, ?User $actor): array
    {
        $from = today()->subDays($days - 1)->toDateString();

        $counts = Absence::query()
            ->where('type', AbsenceType::Absent->value)->where('is_justified', false)
            ->whereHas('lesson', fn ($q) => $q->whereBetween('date', [$from, today()->toDateString()]))
            ->selectRaw('student_id, COUNT(*) as total')->groupBy('student_id')
            ->havingRaw('COUNT(*) >= ?', [$threshold])
            ->pluck('total', 'student_id');

        $resolved = $this->audience->guardiansOf($counts->keys()->map(fn ($id): int => (int) $id)->all(), $channel);
        $week = now()->format('o-\WW');

        $recipients = array_map(function (array $r) use ($counts, $days, $week): array {
            $r['vars'] += ['absences' => (int) $counts[$r['student_id']], 'days' => $days];
            $r['dedupe_key'] = "absence:{$r['student_id']}:{$r['id']}:{$week}";

            return $r;
        }, $resolved['recipients']);

        $template = DefaultTemplates::for(DefaultTemplates::ABSENCE_ALERT);
        $queued = $this->queue->handle($channel, MessageKind::AbsenceAlert, $recipients, $template['subject'], $template['body'], $actor);

        return [
            'students' => $counts->count(), 'queued' => $queued['queued'],
            'duplicates' => $queued['duplicates'], 'skipped' => $resolved['skipped'],
        ];
    }
}
