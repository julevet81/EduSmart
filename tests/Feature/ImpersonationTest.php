<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\ImpersonationSession;
use App\Models\PlatformAdmin;

beforeEach(function (): void {
    config(['saas.impersonation.require_two_factor' => false]);
});

/** Platform admin signs in and enters the tenant as its owner. @return array{0: \App\Models\Tenant, 1: string, 2: string} tenant, platform token, support token */
function supportSession(array $body = []): array
{
    [$tenant] = provisionTenant();
    $platform = platformToken();

    $support = api(null, $platform)->postJson("/api/v1/platform/tenants/{$tenant->id}/impersonate", $body + ['reason' => 'Ticket 1042: cannot see invoices'])
        ->assertCreated()->json('token');

    return [$tenant, $platform, $support];
}

it('lets a platform admin act as the owner with a short, audited token', function (): void {
    [$tenant, $platform, $support] = supportSession();

    $me = api($tenant, $support)->getJson('/api/v1/auth/me')->assertOk()
        ->assertJsonPath('user.email', 'owner@school-a.test')->assertJsonPath('impersonation.can_write', false)
        ->assertJsonPath('impersonation.reason', 'Ticket 1042: cannot see invoices');
    expect($me->json('impersonation.expires_at'))->not->toBeNull();

    $log = AuditLog::query()->where('event', 'platform.impersonation_started')->firstOrFail();
    expect((int) $log->tenant_id)->toBe($tenant->id)->and($log->actor_type)->toBe('platform_admin')
        ->and($log->new_values['reason'])->toBe('Ticket 1042: cannot see invoices');

    // an ordinary login carries no impersonation marker
    $normal = tokenFor($tenant, 'owner@school-a.test');
    api($tenant, $normal)->getJson('/api/v1/auth/me')->assertJsonPath('impersonation', null);
});

it('is read-only unless write access is asked for, and marks every audited change with the support agent', function (): void {
    [$tenant, $platform, $support] = supportSession();

    api($tenant, $support)->getJson('/api/v1/rooms')->assertOk();
    api($tenant, $support)->postJson('/api/v1/rooms', ['name' => 'R1', 'capacity' => 10])->assertForbidden();

    $write = api(null, $platform)->postJson("/api/v1/platform/tenants/{$tenant->id}/impersonate", ['reason' => 'Fix the room list for the client', 'write' => true])
        ->assertCreated()->json('token');

    api($tenant, $write)->postJson('/api/v1/rooms', ['name' => 'R1', 'capacity' => 10])->assertCreated();

    $row = AuditLog::query()->where('event', 'room.created')->firstOrFail();
    $admin = PlatformAdmin::query()->firstOrFail();
    expect($row->actor_type)->toBe('user')->and((int) $row->impersonator_id)->toBe($admin->id);

    // the acts of a normal session carry no impersonator
    $normal = tokenFor($tenant, 'owner@school-a.test');
    api($tenant, $normal)->postJson('/api/v1/rooms', ['name' => 'R2', 'capacity' => 10])->assertCreated();
    expect(AuditLog::query()->where('event', 'room.created')->orderByDesc('id')->first()->impersonator_id)->toBeNull();
});

it('never lets a support session touch credentials, two-factor, accounts or roles, even with write access', function (): void {
    [$tenant, , $support] = supportSession(['write' => true]);

    $calls = [
        ['postJson', '/api/v1/auth/password', ['current_password' => TEST_PASSWORD, 'password' => NEW_PASSWORD_IMP, 'password_confirmation' => NEW_PASSWORD_IMP]],
        ['postJson', '/api/v1/auth/two-factor/setup', ['password' => TEST_PASSWORD]],
        ['postJson', '/api/v1/auth/logout-all', []],
        ['postJson', '/api/v1/auth/logout', []],
        ['postJson', '/api/v1/users', ['name' => 'X', 'email' => 'x@school-a.test', 'password' => NEW_PASSWORD_IMP, 'roles' => ['owner']]],
        ['postJson', '/api/v1/users/1/unlock', []],
        ['postJson', '/api/v1/users/1/two-factor/reset', []],
        ['patchJson', '/api/v1/users/1', ['name' => 'Hacked']],
    ];

    foreach ($calls as [$method, $uri, $body]) {
        api($tenant, $support)->{$method}($uri, $body)->assertForbidden();
    }

    // reading users is still fine
    api($tenant, $support)->getJson('/api/v1/users')->assertOk();
});

const NEW_PASSWORD_IMP = 'An0ther-Str0ng-Pass!';

it('ends when the agent leaves or the platform revokes it, and the token stops working', function (): void {
    [$tenant, $platform, $support] = supportSession();
    $id = ImpersonationSession::query()->firstOrFail()->id;

    api($tenant, $support)->postJson('/api/v1/auth/impersonation/end')->assertNoContent();
    api($tenant, $support)->getJson('/api/v1/auth/me')->assertUnauthorized();
    expect(ImpersonationSession::query()->find($id)->ended_by)->toBe('user');
    expect(AuditLog::query()->where('event', 'platform.impersonation_ended')->count())->toBe(1);

    // a normal token cannot call "end"
    $normal = tokenFor($tenant, 'owner@school-a.test');
    api($tenant, $normal)->postJson('/api/v1/auth/impersonation/end')->assertNotFound();

    // revoked from the platform side
    $second = api(null, $platform)->postJson("/api/v1/platform/tenants/{$tenant->id}/impersonate", ['reason' => 'Second look at the invoices'])->assertCreated();
    $sid = $second->json('session.id');
    api($tenant, $second->json('token'))->getJson('/api/v1/rooms')->assertOk();

    api(null, $platform)->postJson("/api/v1/platform/tenants/{$tenant->id}/impersonations/{$sid}/revoke")->assertOk()->assertJsonPath('data.ended_by', 'admin');
    api($tenant, $second->json('token'))->getJson('/api/v1/rooms')->assertUnauthorized();
});

it('expires on its own', function (): void {
    [$tenant, , $support] = supportSession(['minutes' => 10]);

    api($tenant, $support)->getJson('/api/v1/rooms')->assertOk();
    test()->travel(11)->minutes();
    api($tenant, $support)->getJson('/api/v1/rooms')->assertUnauthorized();
});

it('caps the duration and validates the request', function (): void {
    [$tenant] = provisionTenant();
    $platform = platformToken();
    $url = "/api/v1/platform/tenants/{$tenant->id}/impersonate";

    api(null, $platform)->postJson($url, [])->assertUnprocessable();
    api(null, $platform)->postJson($url, ['reason' => 'short'])->assertUnprocessable();
    api(null, $platform)->postJson($url, ['reason' => 'A proper reason here', 'minutes' => 500])->assertUnprocessable();
    api(null, $platform)->postJson($url, ['reason' => 'A proper reason here', 'user_id' => 999999])->assertUnprocessable();

    // a user of another tenant cannot be targeted through this one
    [$other, $otherOwner] = provisionTenant('school-b');
    api(null, $platform)->postJson($url, ['reason' => 'A proper reason here', 'user_id' => $otherOwner->id])->assertUnprocessable();
});

it('refuses suspended tenants, inactive users, and tenant users and anonymous callers', function (): void {
    [$tenant, $owner] = provisionTenant();
    $platform = platformToken();
    $url = "/api/v1/platform/tenants/{$tenant->id}/impersonate";
    $body = ['reason' => 'A proper reason here'];

    expect(api($tenant, tokenFor($tenant, 'owner@school-a.test'))->postJson($url, $body)->status())->toBeIn([401, 403, 404]);
    api()->postJson($url, $body)->assertUnauthorized();
    expect(ImpersonationSession::query()->count())->toBe(0);

    $owner->forceFill(['is_active' => false])->saveQuietly();
    api(null, $platform)->postJson($url, ['user_id' => $owner->id] + $body)->assertUnprocessable();
    $owner->forceFill(['is_active' => true])->saveQuietly();

    $tenant->update(['status' => 'suspended']);
    api(null, $platform)->postJson($url, $body)->assertUnprocessable();
});

it('requires two-factor on the platform account when configured', function (): void {
    config(['saas.impersonation.require_two_factor' => true]);
    [$tenant] = provisionTenant();
    $platform = platformToken();
    $url = "/api/v1/platform/tenants/{$tenant->id}/impersonate";

    api(null, $platform)->postJson($url, ['reason' => 'A proper reason here'])->assertForbidden();

    PlatformAdmin::query()->firstOrFail()->forceFill(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()])->save();
    api(null, $platform)->postJson($url, ['reason' => 'A proper reason here'])->assertCreated();
});

it('shows every support session to the institution and to the platform', function (): void {
    [$tenant, $platform] = supportSession();

    $mine = api($tenant, tokenFor($tenant, 'owner@school-a.test'))->getJson('/api/v1/support-access')->assertOk()
        ->assertJsonPath('data.0.reason', 'Ticket 1042: cannot see invoices')->assertJsonPath('data.0.by', 'Root')->assertJsonPath('data.0.active', true);
    expect($mine->json('data'))->toHaveCount(1);

    api(null, $platform)->getJson("/api/v1/platform/tenants/{$tenant->id}/impersonations")->assertOk()->assertJsonCount(1, 'data');

    // another institution sees nothing, and a teacher may not look
    [$b] = provisionTenant('school-b');
    api($b, tokenFor($b, 'owner@school-b.test'))->getJson('/api/v1/support-access')->assertOk()->assertJsonCount(0, 'data');
    api($tenant, roleToken($tenant, 'teacher'))->getJson('/api/v1/support-access')->assertForbidden();
});

it('keeps support sessions undeletable', function (): void {
    supportSession();

    expect(fn () => ImpersonationSession::query()->firstOrFail()->delete())->toThrow(LogicException::class);
});
