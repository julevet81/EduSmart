<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Support\Audit\AuditRecorder;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-account lockout on top of the per-IP rate limiter: it stops slow, distributed password guessing.
 * Counting uses plain queries, so it neither fires audit hooks nor races under concurrent attempts.
 */
final class LoginThrottle
{
    public function isLocked(Model $account): bool
    {
        return $account->locked_until !== null && $account->locked_until->isFuture();
    }

    public function recordFailure(Model $account, string $auditPrefix, ?int $tenantId): void
    {
        $account->newQuery()->whereKey($account->getKey())->increment('failed_login_count');
        $count = (int) $account->newQuery()->whereKey($account->getKey())->value('failed_login_count');

        if ($count >= (int) config('saas.lockout.max_attempts')) {
            $account->newQuery()->whereKey($account->getKey())->update([
                'failed_login_count' => 0, 'locked_until' => now()->addMinutes((int) config('saas.lockout.minutes')),
            ]);

            AuditRecorder::event($auditPrefix.'.locked', ['account_id' => $account->getKey()], $tenantId);
        }
    }

    public function recordSuccess(Model $account): void
    {
        $account->newQuery()->whereKey($account->getKey())->update([
            'failed_login_count' => 0, 'locked_until' => null, 'last_login_at' => now(),
        ]);
    }

    public function unlock(Model $account): void
    {
        $account->newQuery()->whereKey($account->getKey())->update(['failed_login_count' => 0, 'locked_until' => null]);
    }
}
