<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\Audit\AuditRecorder;
use App\Support\Auth\Sessions;
use App\Support\Auth\TwoFactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/** Self-service security of the signed-in account (tenant user or platform admin): password and two-factor. */
final class SecurityController extends Controller
{
    public function __construct(private readonly TwoFactor $twoFactor, private readonly Sessions $sessions) {}

    public function changePassword(Request $request): Response
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'different:current_password', Password::defaults()],
        ]);

        $account = $request->user();
        $this->assertPassword($account, $data['current_password'], 'current_password');

        $account->forceFill(['password' => $data['password']])->saveQuietly();
        $this->sessions->revokeOthers($account);
        AuditRecorder::event('auth.password_changed', ['account_id' => $account->getKey()]);

        return response()->noContent();
    }

    public function status(Request $request): JsonResponse
    {
        $account = $request->user();

        return response()->json([
            'enabled' => $account->hasTwoFactor(),
            'pending_setup' => ! $account->hasTwoFactor() && $account->two_factor_secret !== null,
            'recovery_codes_left' => $this->twoFactor->recoveryCodesLeft($account),
        ]);
    }

    /** Step 1: a new secret (QR code on the client). Not active until confirmed. */
    public function setup(Request $request): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string']]);
        $account = $request->user();

        $this->assertPassword($account, $data['password']);
        abort_if($account->hasTwoFactor(), 422, 'Two-factor authentication is already enabled.');

        return response()->json(['data' => $this->twoFactor->begin($account, (string) config('app.name'))]);
    }

    /** Step 2: proves the app works; returns the recovery codes ONCE. */
    public function enable(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:32']]);
        $account = $request->user();

        $codes = $this->twoFactor->confirm($account, $data['code']);

        if ($codes === null) {
            throw ValidationException::withMessages(['code' => ['The code is invalid, or no setup is in progress.']]);
        }

        AuditRecorder::event('auth.two_factor_enabled', ['account_id' => $account->getKey()]);

        return response()->json(['data' => ['recovery_codes' => $codes]]);
    }

    public function disable(Request $request): Response
    {
        $account = $this->assertPasswordAndCode($request);

        $this->twoFactor->disable($account);
        AuditRecorder::event('auth.two_factor_disabled', ['account_id' => $account->getKey()]);

        return response()->noContent();
    }

    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $account = $this->assertPasswordAndCode($request);

        AuditRecorder::event('auth.recovery_codes_regenerated', ['account_id' => $account->getKey()]);

        return response()->json(['data' => ['recovery_codes' => $this->twoFactor->regenerate($account)]]);
    }

    private function assertPasswordAndCode(Request $request): mixed
    {
        $data = $request->validate(['password' => ['required', 'string'], 'code' => ['required', 'string', 'max:32']]);
        $account = $request->user();

        abort_unless($account->hasTwoFactor(), 422, 'Two-factor authentication is not enabled.');
        $this->assertPassword($account, $data['password']);

        if (! $this->twoFactor->check($account, $data['code'])) {
            throw ValidationException::withMessages(['code' => ['The code is invalid.']]);
        }

        return $account;
    }

    private function assertPassword(mixed $account, string $password, string $field = 'password'): void
    {
        if (! Hash::check($password, $account->password)) {
            throw ValidationException::withMessages([$field => ['The password is incorrect.']]);
        }
    }
}
