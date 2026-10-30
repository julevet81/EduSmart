<?php

declare(strict_types=1);

use App\Models\PlatformAdmin;
use App\Models\RefreshToken;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\Totp;
use Illuminate\Testing\TestResponse;

// Token lifetimes must not depend on the developer's .env (e.g. TENANT_TOKEN_TTL_MINUTES=720).
beforeEach(function (): void {
    config(['saas.tokens.tenant_ttl_minutes' => 60, 'saas.tokens.platform_ttl_minutes' => 30]);
});

const NEW_PASSWORD = 'An0ther-Str0ng-Pass!';

/** Enrols two-factor authentication for the signed-in account; returns the secret and the recovery codes. */
function enrollTwoFactor(Tenant|null $tenant, string $token, string $prefix = 'auth'): array
{
    $setup = api($tenant, $token)->postJson("/api/v1/{$prefix}/two-factor/setup", ['password' => TEST_PASSWORD])->assertOk();
    $secret = $setup->json('data.secret');

    test()->travel(31)->seconds();
    $codes = api($tenant, $token)->postJson("/api/v1/{$prefix}/two-factor/enable", ['code' => Totp::now($secret)])->assertOk()->json('data.recovery_codes');

    return [$secret, $codes];
}

/** A code of a later time step than any used so far (the replay guard refuses the same step twice). */
function freshCode(string $secret): string
{
    test()->travel(31)->seconds();

    return Totp::now($secret);
}

function startLogin(Tenant $tenant, string $email, string $password = TEST_PASSWORD): TestResponse
{
    return api($tenant)->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password]);
}

function answerChallenge(Tenant $tenant, string $challenge, string $code): TestResponse
{
    return api($tenant)->postJson('/api/v1/auth/two-factor/challenge', ['challenge' => $challenge, 'code' => $code]);
}

function refreshWith(Tenant $tenant, string $refresh): TestResponse
{
    return api($tenant)->postJson('/api/v1/auth/refresh', ['refresh_token' => $refresh]);
}

it('enrols two-factor authentication and then requires the second step to log in', function (): void {
    [$tenant, $owner] = provisionTenant();
    $token = tokenFor($tenant, $owner->email);

    api($tenant, $token)->postJson('/api/v1/auth/two-factor/setup', ['password' => 'wrong'])->assertUnprocessable()->assertJsonValidationErrors('password');
    api($tenant, $token)->postJson('/api/v1/auth/two-factor/enable', ['code' => '123456'])->assertUnprocessable(); // no setup yet

    $setup = api($tenant, $token)->postJson('/api/v1/auth/two-factor/setup', ['password' => TEST_PASSWORD])->assertOk();
    expect($setup->json('data.otpauth_uri'))->toStartWith('otpauth://totp/')->toContain($setup->json('data.secret'));
    api($tenant, $token)->getJson('/api/v1/auth/two-factor')->assertJsonPath('enabled', false)->assertJsonPath('pending_setup', true);

    api($tenant, $token)->postJson('/api/v1/auth/two-factor/enable', ['code' => '000000'])->assertUnprocessable()->assertJsonValidationErrors('code');

    test()->travel(31)->seconds();
    $codes = api($tenant, $token)->postJson('/api/v1/auth/two-factor/enable', ['code' => Totp::now($setup->json('data.secret'))])->assertOk()->json('data.recovery_codes');
    expect($codes)->toHaveCount(10)->and($codes[0])->toMatch('/^[A-Z2-9]{5}-[A-Z2-9]{5}$/');
    api($tenant, $token)->getJson('/api/v1/auth/two-factor')->assertJsonPath('enabled', true)->assertJsonPath('recovery_codes_left', 10);
    api($tenant, $token)->postJson('/api/v1/auth/two-factor/setup', ['password' => TEST_PASSWORD])->assertUnprocessable(); // already enabled
    api($tenant, $token)->getJson('/api/v1/auth/me')->assertJsonPath('two_factor_enabled', true);

    // password alone no longer yields a token
    $login = startLogin($tenant, $owner->email)->assertOk()->assertJsonPath('two_factor_required', true)->assertJsonMissingPath('token');
    $challenge = $login->json('challenge');

    answerChallenge($tenant, $challenge, '000000')->assertUnauthorized();
    answerChallenge($tenant, $challenge, 'x')->assertUnauthorized();

    $secret = $setup->json('data.secret');
    $code = freshCode($secret);
    $session = answerChallenge($tenant, $challenge, $code)->assertOk()->assertJsonStructure(['token', 'refresh_token', 'user' => ['id']]);
    api($tenant, $session->json('token'))->getJson('/api/v1/auth/me')->assertOk();

    // the challenge is single use, and a captured code cannot be replayed on a new challenge
    answerChallenge($tenant, $challenge, $code)->assertUnauthorized();
    $again = startLogin($tenant, $owner->email)->json('challenge');
    answerChallenge($tenant, $again, $code)->assertUnauthorized();
    answerChallenge($tenant, $again, freshCode($secret))->assertOk();
});

it('gives up a challenge after five wrong codes and rejects foreign or unknown ones', function (): void {
    [$tenant, $owner] = provisionTenant();
    [$secret] = enrollTwoFactor($tenant, tokenFor($tenant, $owner->email));
    [$other] = provisionTenant('school-b');

    $challenge = startLogin($tenant, $owner->email)->json('challenge');

    answerChallenge($other, $challenge, Totp::now($secret))->assertUnauthorized();   // bound to its own tenant
    answerChallenge($tenant, str_repeat('a', 64), Totp::now($secret))->assertUnauthorized();

    foreach (range(1, 5) as $_) {
        answerChallenge($tenant, $challenge, '111111')->assertUnauthorized();
    }

    answerChallenge($tenant, $challenge, freshCode($secret))->assertUnauthorized();  // the challenge is gone, start again
    answerChallenge($tenant, startLogin($tenant, $owner->email)->json('challenge'), freshCode($secret))->assertOk();
});

it('accepts each recovery code once and lets the owner regenerate them', function (): void {
    [$tenant, $owner] = provisionTenant();
    $token = tokenFor($tenant, $owner->email);
    [$secret, $codes] = enrollTwoFactor($tenant, $token);

    $challenge = fn () => startLogin($tenant, $owner->email)->json('challenge');

    answerChallenge($tenant, $challenge(), strtolower($codes[0]))->assertOk();            // case-insensitive
    answerChallenge($tenant, $challenge(), $codes[0])->assertUnauthorized();               // used up
    answerChallenge($tenant, $challenge(), str_replace('-', ' ', $codes[1]))->assertOk();  // separator tolerant
    api($tenant, $token)->getJson('/api/v1/auth/two-factor')->assertJsonPath('recovery_codes_left', 8);

    api($tenant, $token)->postJson('/api/v1/auth/two-factor/recovery-codes', ['password' => 'wrong', 'code' => freshCode($secret)])->assertUnprocessable();
    api($tenant, $token)->postJson('/api/v1/auth/two-factor/recovery-codes', ['password' => TEST_PASSWORD, 'code' => '000000'])->assertUnprocessable();
    $new = api($tenant, $token)->postJson('/api/v1/auth/two-factor/recovery-codes', ['password' => TEST_PASSWORD, 'code' => freshCode($secret)])
        ->assertOk()->json('data.recovery_codes');

    expect($new)->toHaveCount(10);
    answerChallenge($tenant, $challenge(), $codes[2])->assertUnauthorized();               // the old set is void
    answerChallenge($tenant, $challenge(), $new[0])->assertOk();
});

it('disables two-factor authentication only with the password and a valid code', function (): void {
    [$tenant, $owner] = provisionTenant();
    $token = tokenFor($tenant, $owner->email);
    [$secret] = enrollTwoFactor($tenant, $token);

    api($tenant, $token)->postJson('/api/v1/auth/two-factor/disable', ['password' => TEST_PASSWORD, 'code' => '000000'])->assertUnprocessable();
    api($tenant, $token)->postJson('/api/v1/auth/two-factor/disable', ['password' => 'wrong', 'code' => freshCode($secret)])->assertUnprocessable();
    api($tenant, $token)->postJson('/api/v1/auth/two-factor/disable', ['password' => TEST_PASSWORD, 'code' => freshCode($secret)])->assertNoContent();

    startLogin($tenant, $owner->email)->assertOk()->assertJsonMissingPath('two_factor_required')->assertJsonStructure(['token']);
    api($tenant, $token)->postJson('/api/v1/auth/two-factor/disable', ['password' => TEST_PASSWORD, 'code' => '123456'])->assertUnprocessable(); // not enabled
});

it('renews sessions with single-use refresh tokens and expires them', function (): void {
    config(['saas.tokens.tenant_ttl_minutes' => 60]); // independent of any TENANT_TOKEN_TTL_MINUTES set in .env
    [$tenant, $owner] = provisionTenant();
    $login = startLogin($tenant, $owner->email)->assertOk()->assertJsonStructure(['token', 'refresh_token', 'refresh_expires_at', 'expires_at']);
    [$access, $refresh] = [$login->json('token'), $login->json('refresh_token')];

    refreshWith($tenant, 'x')->assertUnprocessable();
    refreshWith($tenant, str_repeat('z', 64))->assertUnauthorized();

    $renewed = refreshWith($tenant, $refresh)->assertOk()->assertJsonStructure(['token', 'refresh_token', 'user' => ['id']]);
    expect($renewed->json('refresh_token'))->not->toBe($refresh);

    api($tenant, $access)->getJson('/api/v1/auth/me')->assertUnauthorized();                      // the old access token died with the rotation
    api($tenant, $renewed->json('token'))->getJson('/api/v1/auth/me')->assertOk();

    // presenting the used token again means it leaked: the whole family is revoked
    refreshWith($tenant, $refresh)->assertUnauthorized();
    api($tenant, $renewed->json('token'))->getJson('/api/v1/auth/me')->assertUnauthorized();
    refreshWith($tenant, $renewed->json('refresh_token'))->assertUnauthorized();

    // a short access token is simply renewed; a refresh token left unused for too long is not
    $fresh = startLogin($tenant, $owner->email)->json();
    test()->travel(61)->minutes();
    api($tenant, $fresh['token'])->getJson('/api/v1/auth/me')->assertUnauthorized();
    $next = refreshWith($tenant, $fresh['refresh_token'])->assertOk()->json();
    api($tenant, $next['token'])->getJson('/api/v1/auth/me')->assertOk();

    test()->travel(31)->days();
    refreshWith($tenant, $next['refresh_token'])->assertUnauthorized();
});

it('never extends a login beyond its absolute lifetime', function (): void {
    config(['saas.tokens.refresh_max_days' => 2, 'saas.tokens.tenant_refresh_days' => 30]);
    [$tenant, $owner] = provisionTenant();

    $session = startLogin($tenant, $owner->email)->json();
    test()->travel(25)->hours();
    $session = refreshWith($tenant, $session['refresh_token'])->assertOk()->json();
    expect(RefreshToken::query()->where('used_at', null)->first()->expires_at->diffInHours(now(), true))->toBeLessThanOrEqual(24);

    test()->travel(25)->hours();
    refreshWith($tenant, $session['refresh_token'])->assertUnauthorized();
});

it('ends refresh tokens on logout, deactivation, password change and foreign tenants', function (): void {
    [$tenant, $owner] = provisionTenant();
    roleToken($tenant, 'secretary');
    $secretaryId = (int) inTenant($tenant, fn () => User::query()->where('email', "secretary@{$tenant->slug}.test")->value('id'));

    $session = startLogin($tenant, $owner->email)->json();
    api($tenant, $session['token'])->postJson('/api/v1/auth/logout')->assertNoContent();
    refreshWith($tenant, $session['refresh_token'])->assertUnauthorized();

    $session = startLogin($tenant, $owner->email)->json();
    api($tenant, $session['token'])->postJson('/api/v1/auth/logout-all')->assertNoContent();
    refreshWith($tenant, $session['refresh_token'])->assertUnauthorized();

    [$other] = provisionTenant('school-b');
    $mine = startLogin($tenant, $owner->email)->json();
    refreshWith($other, $mine['refresh_token'])->assertUnauthorized();                              // another tenant never honours it

    $ownerToken = tokenFor($tenant, $owner->email);                                                  // logout-all above ended every owner session
    $secretary = startLogin($tenant, "secretary@{$tenant->slug}.test")->json();
    api($tenant, $ownerToken)->patchJson("/api/v1/users/{$secretaryId}", ['is_active' => false])->assertOk();
    refreshWith($tenant, $secretary['refresh_token'])->assertUnauthorized();
    api($tenant, $ownerToken)->patchJson("/api/v1/users/{$secretaryId}", ['is_active' => true, 'password' => NEW_PASSWORD])->assertOk();
    $afterReset = startLogin($tenant, "secretary@{$tenant->slug}.test", NEW_PASSWORD)->assertOk()->json();
    api($tenant, $ownerToken)->patchJson("/api/v1/users/{$secretaryId}", ['password' => 'Yet-An0ther-Pass1'])->assertOk();
    refreshWith($tenant, $afterReset['refresh_token'])->assertUnauthorized();                       // an admin password reset signs the user out everywhere
});

it('changes the password and signs out every other device', function (): void {
    [$tenant, $owner] = provisionTenant();
    $here = startLogin($tenant, $owner->email)->json();
    $elsewhere = startLogin($tenant, $owner->email)->json();

    api($tenant, $here['token'])->postJson('/api/v1/auth/password', ['current_password' => 'wrong', 'password' => NEW_PASSWORD])
        ->assertUnprocessable()->assertJsonValidationErrors('current_password');
    api($tenant, $here['token'])->postJson('/api/v1/auth/password', ['current_password' => TEST_PASSWORD, 'password' => 'weak'])
        ->assertUnprocessable()->assertJsonValidationErrors('password');
    api($tenant, $here['token'])->postJson('/api/v1/auth/password', ['current_password' => TEST_PASSWORD, 'password' => TEST_PASSWORD])
        ->assertUnprocessable()->assertJsonValidationErrors('password');
    api($tenant, $here['token'])->postJson('/api/v1/auth/password', ['current_password' => TEST_PASSWORD, 'password' => NEW_PASSWORD])->assertNoContent();

    api($tenant, $here['token'])->getJson('/api/v1/auth/me')->assertOk();
    api($tenant, $elsewhere['token'])->getJson('/api/v1/auth/me')->assertUnauthorized();
    refreshWith($tenant, $elsewhere['refresh_token'])->assertUnauthorized();
    refreshWith($tenant, $here['refresh_token'])->assertOk();

    startLogin($tenant, $owner->email)->assertUnauthorized();
    startLogin($tenant, $owner->email, NEW_PASSWORD)->assertOk();
});

it('locks an account after repeated failures and lets an administrator unlock it', function (): void {
    config(['saas.lockout.max_attempts' => 2]);
    [$tenant, $owner] = provisionTenant();
    $ownerToken = tokenFor($tenant, $owner->email);
    $secretary = roleToken($tenant, 'secretary');
    $email = "secretary@{$tenant->slug}.test";
    $id = (int) inTenant($tenant, fn () => User::query()->where('email', $email)->value('id'));

    foreach (range(1, 2) as $_) {
        startLogin($tenant, $email, 'wrong-password')->assertUnauthorized();
    }
    startLogin($tenant, $email)->assertUnauthorized();                                           // the right password is refused while locked
    api($tenant, $secretary)->postJson("/api/v1/users/{$id}/unlock")->assertForbidden();         // the secretary cannot unlock anyone

    api($tenant, $ownerToken)->postJson("/api/v1/users/{$id}/unlock")->assertNoContent();
    startLogin($tenant, $email)->assertOk();

    test()->travel(61)->seconds();                                                               // a fresh rate-limit window
    foreach (range(1, 2) as $_) {
        startLogin($tenant, $email, 'wrong-password')->assertUnauthorized();
    }
    test()->travel(16)->minutes();
    startLogin($tenant, $email)->assertOk();                                                     // the lock expires by itself

    // a manager cannot unlock an owner
    $manager = roleToken($tenant, 'manager');
    api($tenant, $manager)->postJson('/api/v1/users/'.$owner->id.'/unlock')->assertForbidden();
});

it('lets administrators reset the two-factor authentication of a colleague, but not their own', function (): void {
    [$tenant, $owner] = provisionTenant();
    $ownerToken = tokenFor($tenant, $owner->email);
    $manager = roleToken($tenant, 'manager');
    $managerId = (int) inTenant($tenant, fn () => User::query()->where('email', "manager@{$tenant->slug}.test")->value('id'));
    [$secret] = enrollTwoFactor($tenant, $manager);
    $session = startLogin($tenant, "manager@{$tenant->slug}.test")->assertJsonPath('two_factor_required', true)->json();
    $live = answerChallenge($tenant, $session['challenge'], freshCode($secret))->json();

    api($tenant, $manager)->postJson("/api/v1/users/{$managerId}/two-factor/reset")->assertUnprocessable();                 // own account: use disable
    api($tenant, $manager)->postJson("/api/v1/users/{$owner->id}/two-factor/reset")->assertForbidden();                      // higher privileges
    api($tenant, roleToken($tenant, 'teacher'))->postJson("/api/v1/users/{$managerId}/two-factor/reset")->assertForbidden();

    api($tenant, $ownerToken)->postJson("/api/v1/users/{$managerId}/two-factor/reset")->assertNoContent();
    api($tenant, $live['token'])->getJson('/api/v1/auth/me')->assertUnauthorized();                                           // sessions ended
    startLogin($tenant, "manager@{$tenant->slug}.test")->assertOk()->assertJsonStructure(['token']);                          // plain login again
});

it('protects platform administrators with the same two-factor and refresh flow', function (): void {
    $token = platformToken();
    [$secret] = enrollTwoFactor(null, $token, 'platform/auth');

    $login = api()->postJson('/api/v1/platform/auth/login', ['email' => 'root@platform.test', 'password' => TEST_PASSWORD])
        ->assertOk()->assertJsonPath('two_factor_required', true)->assertJsonMissingPath('token');
    $challenge = fn (string $code) => api()->postJson('/api/v1/platform/auth/two-factor/challenge', ['challenge' => $login->json('challenge'), 'code' => $code]);

    $challenge('000000')->assertUnauthorized();
    $session = $challenge(freshCode($secret))->assertOk()->assertJsonStructure(['token', 'refresh_token', 'admin' => ['id']])->json();
    api(null, $session['token'])->getJson('/api/v1/platform/auth/me')->assertOk()->assertJsonPath('two_factor_enabled', true);

    $renewed = api()->postJson('/api/v1/platform/auth/refresh', ['refresh_token' => $session['refresh_token']])->assertOk()->json();
    api(null, $session['token'])->getJson('/api/v1/platform/auth/me')->assertUnauthorized();
    api()->postJson('/api/v1/platform/auth/refresh', ['refresh_token' => $session['refresh_token']])->assertUnauthorized(); // reuse
    api(null, $renewed['token'])->getJson('/api/v1/platform/auth/me')->assertUnauthorized();                               // family revoked

    // a tenant refresh token is useless against the platform and vice versa
    [$tenant, $owner] = provisionTenant();
    $tenantSession = startLogin($tenant, $owner->email)->json();
    api()->postJson('/api/v1/platform/auth/refresh', ['refresh_token' => $tenantSession['refresh_token']])->assertUnauthorized();
    expect(PlatformAdmin::query()->count())->toBe(1);
});

it('prunes refresh tokens that can no longer be used', function (): void {
    [$tenant, $owner] = provisionTenant();
    $session = startLogin($tenant, $owner->email)->json();
    startLogin($tenant, $owner->email);
    test()->travel(100)->days();

    $this->artisan('auth:prune-refresh-tokens')->assertSuccessful();
    expect(RefreshToken::query()->count())->toBe(0);
    refreshWith($tenant, $session['refresh_token'])->assertUnauthorized();
});
