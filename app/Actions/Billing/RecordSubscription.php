<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Enums\TenantStatus;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Support\Audit\AuditRecorder;
use App\Support\Billing\Subscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Records a payment received for a tenant and extends its coverage.
 * The new period starts the day after the current coverage ends (so an unfinished trial or paid period is never lost),
 * or today when coverage already lapsed. Replaying the same idempotency key returns the original period.
 */
final class RecordSubscription
{
    /**
     * @param  array{plan: string, months: int, amount?: int|null, method: string, reference?: string|null, paid_on?: string|null, note?: string|null, idempotency_key?: string|null, force?: bool}  $data
     * @return array{subscription: TenantSubscription, replayed: bool}
     */
    public function handle(Tenant $tenant, array $data, ?int $adminId): array
    {
        return DB::transaction(function () use ($tenant, $data, $adminId): array {
            $locked = Tenant::query()->lockForUpdate()->findOrFail($tenant->getKey());
            $key = $data['idempotency_key'] ?? null;

            if ($key !== null) {
                $existing = TenantSubscription::query()->where('tenant_id', $locked->getKey())->where('idempotency_key', $key)->first();

                if ($existing !== null) {
                    return ['subscription' => $existing, 'replayed' => true];
                }
            }

            abort_if(in_array($locked->status, [TenantStatus::Suspended, TenantStatus::Archived], true), 422, 'A suspended or archived tenant cannot be renewed; reactivate it first.');

            $plan = Plan::query()->where('code', $data['plan'])->firstOrFail();
            abort_unless($plan->is_active, 422, 'This plan is no longer offered.');

            $this->assertFits($locked, $plan, (bool) ($data['force'] ?? false));

            $end = Subscription::coverageEnd($locked);
            $today = Carbon::today();
            $start = $end !== null && $end->gte($today) ? $end->copy()->addDay() : $today;
            $periodEnd = $start->copy()->addMonthsNoOverflow((int) $data['months'])->subDay();

            $row = TenantSubscription::query()->create([
                'tenant_id' => $locked->getKey(), 'plan_id' => $plan->getKey(),
                'period_start' => $start, 'period_end' => $periodEnd, 'months' => $data['months'],
                'amount' => $data['amount'] ?? $plan->price_monthly * $data['months'],
                'method' => $data['method'], 'reference' => $data['reference'] ?? null,
                'paid_on' => $data['paid_on'] ?? $today->toDateString(), 'note' => $data['note'] ?? null,
                'idempotency_key' => $key, 'status' => 'active', 'recorded_by' => $adminId,
            ]);

            $locked->update([
                'plan' => $plan->code,
                'subscription_ends_at' => $periodEnd,
                'status' => $locked->status->value === TenantStatus::Trial->value && $start->gt($today) ? TenantStatus::Trial : TenantStatus::Active,
            ]);

            AuditRecorder::event('subscription.recorded', [
                'subscription_id' => $row->getKey(), 'plan' => $plan->code, 'months' => $row->months, 'amount' => $row->amount,
                'period_start' => $start->toDateString(), 'period_end' => $periodEnd->toDateString(),
            ], (int) $locked->getKey());

            return ['subscription' => $row, 'replayed' => false];
        });
    }

    /** Refuses a plan that is smaller than what the tenant already uses, unless the platform forces it. */
    private function assertFits(Tenant $tenant, Plan $plan, bool $force): void
    {
        if ($force) {
            return;
        }

        $usage = Subscription::usage($tenant);

        foreach (['students' => 'max_students', 'users' => 'max_users'] as $used => $limit) {
            abort_if(
                $plan->{$limit} !== null && $usage[$used] > $plan->{$limit},
                422,
                "The tenant uses {$usage[$used]} {$used} but the {$plan->name} plan allows {$plan->{$limit}}. Reduce usage or send force=true.",
            );
        }
    }
}
