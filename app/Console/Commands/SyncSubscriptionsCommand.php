<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Support\Audit\AuditRecorder;
use App\Support\Billing\Subscription;
use Illuminate\Console\Command;

/** Keeps each tenant's status label (trial / active / past_due / read_only) in step with its dates. Enforcement itself never waits for this. */
final class SyncSubscriptionsCommand extends Command
{
    protected $signature = 'subscriptions:sync';

    protected $description = 'Relabel tenants according to their trial / subscription dates';

    public function handle(): int
    {
        $changed = 0;

        Tenant::query()->whereNotIn('status', [TenantStatus::Suspended->value, TenantStatus::Archived->value])
            ->orderBy('id')->each(function (Tenant $tenant) use (&$changed): void {
                $label = Subscription::label($tenant);

                if ($label !== $tenant->status) {
                    AuditRecorder::event('tenant.status_changed', ['from' => $tenant->status->value, 'to' => $label->value], (int) $tenant->getKey());
                    $tenant->update(['status' => $label]);
                    $changed++;
                }
            });

        $this->info("Relabelled {$changed} tenant(s).");

        return self::SUCCESS;
    }
}
