<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Models\Student;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * Plan quotas, checked by model hooks (see AppServiceProvider) so that no code path can bypass them.
 * Only ACTIVE students and staff accounts count; deactivating one frees the slot. Legacy tenants without a plan are unlimited.
 */
final class PlanLimits
{
    private static bool $skip = false;

    public function __construct(private readonly TenantContext $context) {}

    /** Runs $callback without quota checks (portal accounts are free). */
    public static function skipping(callable $callback): mixed
    {
        $previous = self::$skip;
        self::$skip = true;

        try {
            return $callback();
        } finally {
            self::$skip = $previous;
        }
    }

    public function assertStudentSlot(): void
    {
        $this->assert('max_students', 'students', 'students');
    }

    public function assertUserSlot(): void
    {
        $this->assert('max_users', 'users', 'staff accounts');
    }

    private function assert(string $limit, string $usageKey, string $label): void
    {
        if (self::$skip || $this->context->id() === null) {
            return;
        }

        $tenant = $this->context->require();
        $plan = Subscription::plan($tenant);
        $max = $plan?->{$limit};

        if ($max === null) {
            return;
        }

        if (Subscription::usage($tenant)[$usageKey] >= $max) {
            abort(402, "Your plan ({$plan->name}) allows {$max} {$label}. Upgrade the plan or deactivate some to add more.");
        }
    }

    /** Registered once at boot. */
    public static function register(): void
    {
        Student::saving(static function (Student $student): void {
            $adding = $student->exists ? $student->isDirty('is_active') && $student->is_active : ($student->getAttribute('is_active') ?? true);

            if ($adding) {
                app(self::class)->assertStudentSlot();
            }
        });

        User::saving(static function (User $user): void {
            $adding = $user->exists ? $user->isDirty('is_active') && $user->is_active : ($user->getAttribute('is_active') ?? true);

            // portal accounts (role parent / student) never count against the staff quota
            if ($adding && ! ($user->exists && $user->hasAnyRole(['parent', 'student']))) {
                app(self::class)->assertUserSlot();
            }
        });
    }
}
