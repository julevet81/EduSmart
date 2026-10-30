<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Enums\TenantStatus;
use App\Models\Plan;
use App\Models\Student;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;

/**
 * What a tenant may do, derived from DATES (trial end, paid-period end, grace days) so that enforcement never depends
 * on a scheduled job having run. The "status" column is only a label kept in step by `subscriptions:sync`.
 *
 *   full       covered by the trial or a paid period
 *   grace      coverage ended less than <grace_days> ago: everything still works, the owner is warned
 *   read_only  grace over: reads and sign-in work, every other write is refused (HTTP 402)
 *   blocked    suspended or archived by the platform
 */
final class Subscription
{
    public const FULL = 'full';

    public const GRACE = 'grace';

    public const READ_ONLY = 'read_only';

    public const BLOCKED = 'blocked';

    /** The last covered day: the later of the trial end and the paid-period end. Null = never expires. */
    public static function coverageEnd(Tenant $tenant): ?Carbon
    {
        $ends = array_filter([$tenant->subscription_ends_at?->copy()->startOfDay(), $tenant->trial_ends_at?->copy()->startOfDay()]);

        return $ends === [] ? null : collect($ends)->max();
    }

    public static function graceEnd(Tenant $tenant): ?Carbon
    {
        return self::coverageEnd($tenant)?->copy()->addDays((int) config('saas.subscription.grace_days'));
    }

    public static function accessLevel(Tenant $tenant): string
    {
        if (in_array($tenant->status, [TenantStatus::Suspended, TenantStatus::Archived], true)) {
            return self::BLOCKED;
        }

        $end = self::coverageEnd($tenant);
        $today = Carbon::today();

        return match (true) {
            $end === null, $today->lte($end) => self::FULL,
            $today->lte(self::graceEnd($tenant)) => self::GRACE,
            default => self::READ_ONLY,
        };
    }

    /** The status label that matches the dates (suspended and archived tenants are never relabelled). */
    public static function label(Tenant $tenant): TenantStatus
    {
        return match (self::accessLevel($tenant)) {
            self::BLOCKED => $tenant->status,
            self::READ_ONLY => TenantStatus::ReadOnly,
            self::GRACE => TenantStatus::PastDue,
            default => $tenant->subscription_ends_at !== null && $tenant->subscription_ends_at->copy()->startOfDay()->gte(Carbon::today())
                ? TenantStatus::Active
                : ($tenant->trial_ends_at === null ? TenantStatus::Active : TenantStatus::Trial),
        };
    }

    /** The plan the tenant is on; null (no limits) for legacy tenants without a known plan. */
    public static function plan(Tenant $tenant): ?Plan
    {
        return $tenant->plan === null ? null : Plan::query()->where('code', $tenant->plan)->first();
    }

    /** @return array{students: int, users: int} active students and staff accounts (portal accounts are free) */
    public static function usage(Tenant $tenant): array
    {
        return app(TenantContext::class)->run($tenant, static fn (): array => [
            'students' => Student::query()->where('is_active', true)->count(),
            'users' => User::query()->where('is_active', true)
                ->whereDoesntHave('roles', fn ($q) => $q->whereIn('name', ['parent', 'student']))->count(),
        ]);
    }

    /** @return array<string, mixed> */
    public static function state(Tenant $tenant): array
    {
        $plan = self::plan($tenant);
        $end = self::coverageEnd($tenant);

        return [
            'status' => $tenant->status->value,
            'access_level' => self::accessLevel($tenant),
            'plan' => $plan === null ? null : [
                'code' => $plan->code, 'name' => $plan->name, 'max_students' => $plan->max_students, 'max_users' => $plan->max_users,
            ],
            'usage' => self::usage($tenant),
            'trial_ends_on' => $tenant->trial_ends_at?->toDateString(),
            'paid_until' => $tenant->subscription_ends_at?->toDateString(),
            'coverage_ends_on' => $end?->toDateString(),
            'grace_ends_on' => self::graceEnd($tenant)?->toDateString(),
            'days_left' => $end === null ? null : (int) Carbon::today()->diffInDays($end, false),
        ];
    }
}
