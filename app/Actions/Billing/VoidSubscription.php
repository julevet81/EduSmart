<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Support\Audit\AuditRecorder;
use App\Support\Billing\Subscription;
use Illuminate\Support\Facades\DB;

/** Cancels a recorded period (wrong amount, bounced cheque...) and recomputes the tenant's coverage from what remains. */
final class VoidSubscription
{
    public function handle(Tenant $tenant, int $subscriptionId, string $reason): TenantSubscription
    {
        return DB::transaction(function () use ($tenant, $subscriptionId, $reason): TenantSubscription {
            $locked = Tenant::query()->lockForUpdate()->findOrFail($tenant->getKey());
            $row = TenantSubscription::query()->where('tenant_id', $locked->getKey())->findOrFail($subscriptionId);

            abort_unless($row->status === 'active', 422, 'This period is already void.');

            $row->update(['status' => 'void', 'voided_at' => now(), 'void_reason' => $reason]);

            $latest = TenantSubscription::query()->with('plan')->where('tenant_id', $locked->getKey())
                ->where('status', 'active')->orderByDesc('period_end')->first();

            $locked->forceFill([
                'subscription_ends_at' => $latest?->period_end,
                'plan' => $latest?->plan->code ?? $locked->plan,
            ])->save();
            $locked->forceFill(['status' => Subscription::label($locked->refresh())])->save();

            AuditRecorder::event('subscription.voided', ['subscription_id' => $row->getKey(), 'reason' => $reason], (int) $locked->getKey());

            return $row->refresh();
        });
    }
}
