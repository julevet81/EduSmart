<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Auth\LoginThrottle;
use App\Support\Auth\Sessions;
use App\Support\Auth\TwoFactor;
use App\Support\Rbac\RoleGuard;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** What an administrator can do for a colleague who lost the phone or locked the account. */
final class UserSecurityController extends Controller
{
    public function __construct(
        private readonly RoleGuard $guard,
        private readonly TwoFactor $twoFactor,
        private readonly Sessions $sessions,
        private readonly LoginThrottle $throttle,
    ) {}

    public function resetTwoFactor(Request $request, User $user): Response
    {
        abort_if($request->user()->is($user), 422, 'Use the two-factor disable endpoint for your own account.');
        $this->guard->assertCanManage($request->user(), $user);

        $this->twoFactor->disable($user);
        $this->sessions->revokeAll($user);
        AuditRecorder::event('user.two_factor_reset', ['user_id' => $user->getKey()]);

        return response()->noContent();
    }

    public function unlock(Request $request, User $user): Response
    {
        $this->guard->assertCanManage($request->user(), $user);

        $this->throttle->unlock($user);
        AuditRecorder::event('user.unlocked', ['user_id' => $user->getKey()]);

        return response()->noContent();
    }
}
