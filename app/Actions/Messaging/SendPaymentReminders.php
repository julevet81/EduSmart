<?php

declare(strict_types=1);

namespace App\Actions\Messaging;

use App\Enums\InvoiceStatus;
use App\Enums\MessageChannel;
use App\Enums\MessageKind;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Messaging\AudienceResolver;
use App\Support\Messaging\DefaultTemplates;

/**
 * Reminds the (financially responsible) guardians of every student whose invoices are overdue by at least $minOverdueDays.
 * One message per student summing everything still due; at most one per (guardian, student, ISO week).
 */
final class SendPaymentReminders
{
    public function __construct(private readonly AudienceResolver $audience, private readonly QueueMessages $queue) {}

    /** @return array{students: int, queued: int, duplicates: int, skipped: int} */
    public function handle(int $minOverdueDays, MessageChannel $channel, ?User $actor): array
    {
        $limit = today()->subDays($minOverdueDays)->toDateString();

        $debts = Invoice::query()
            ->whereIn('status', [InvoiceStatus::Open->value, InvoiceStatus::PartiallyPaid->value])
            ->where('due_date', '<=', $limit)
            ->selectRaw('student_id, SUM(total_amount - paid_amount) as due, MIN(due_date) as oldest')
            ->groupBy('student_id')->get()->keyBy('student_id');

        $resolved = $this->audience->guardiansOf($debts->keys()->map(fn ($id): int => (int) $id)->all(), $channel, financialOnly: true);
        $week = now()->format('o-\WW');

        $recipients = array_map(function (array $r) use ($debts, $week): array {
            $debt = $debts[$r['student_id']];
            $r['vars'] += ['amount' => number_format(((int) $debt->due) / 100, 2, ',', ' '), 'due_date' => (string) $debt->oldest];
            $r['dedupe_key'] = "overdue:{$r['student_id']}:{$r['id']}:{$week}";

            return $r;
        }, $resolved['recipients']);

        $template = DefaultTemplates::for(DefaultTemplates::PAYMENT_REMINDER);
        $queued = $this->queue->handle($channel, MessageKind::PaymentReminder, $recipients, $template['subject'], $template['body'], $actor);

        return [
            'students' => $debts->count(), 'queued' => $queued['queued'],
            'duplicates' => $queued['duplicates'], 'skipped' => $resolved['skipped'],
        ];
    }
}
