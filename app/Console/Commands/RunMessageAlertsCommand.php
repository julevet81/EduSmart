<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Messaging\SendAbsenceAlerts;
use App\Actions\Messaging\SendPaymentReminders;
use App\Enums\MessageChannel;
use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

final class RunMessageAlertsCommand extends Command
{
    protected $signature = 'messages:alerts {--days=30} {--threshold=3} {--overdue-days=1} {--channel=sms}';

    protected $description = 'Queue the absence alerts and the overdue-payment reminders of every active tenant (idempotent per week). Schedule it daily.';

    public function handle(SendAbsenceAlerts $absences, SendPaymentReminders $payments, TenantContext $context): int
    {
        $channel = MessageChannel::tryFrom((string) $this->option('channel'));

        if ($channel === null || $channel === MessageChannel::InApp) {
            $this->error('The channel must be sms, whatsapp or email.');

            return self::FAILURE;
        }

        Tenant::query()->whereIn('status', [TenantStatus::Trial->value, TenantStatus::Active->value, TenantStatus::PastDue->value])->orderBy('id')->each(function (Tenant $tenant) use ($absences, $payments, $context, $channel): void {
            $context->run($tenant, function () use ($tenant, $absences, $payments, $channel): void {
                $a = $absences->handle((int) $this->option('days'), (int) $this->option('threshold'), $channel, null);
                $p = $payments->handle((int) $this->option('overdue-days'), $channel, null);
                $this->line("{$tenant->slug}: absence alerts {$a['queued']}, payment reminders {$p['queued']}");
            });
        });

        return self::SUCCESS;
    }
}
