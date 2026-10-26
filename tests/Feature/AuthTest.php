<?php

declare(strict_types=1);

use App\Enums\TenantStatus;
use Laravel\Sanctum\PersonalAccessToken;

it('logs a tenant user in and returns a token', function (): void {
    [$tenant, $owner] = provisionTenant();

    loginAs($tenant, $owner->email)
        ->assertOk()
        ->assertJsonStructure(['token', 'token_type', 'expires_at', 'user' => ['id', 'email', 'roles']])
        ->assertJsonPath('user.roles.0', 'owner');
});

it('rejects wrong credentials with a generic message', function (): void {
    [$tenant, $owner] = provisionTenant();

    loginAs($tenant, $owner->email, 'wrong-password')->assertUnauthorized();
    loginAs($tenant, 'nobody@school-a.test')->assertUnauthorized();
});

it('does not accept a user of another tenant', function (): void {
    [$a] = provisionTenant('school-a');
    [, $ownerB] = provisionTenant('school-b');

    loginAs($a, $ownerB->email)->assertUnauthorized();
});

it('returns 400 without a tenant and 404 for an unknown one', function (): void {
    provisionTenant();

    api()->postJson('/api/v1/auth/login', ['email' => 'a@b.test', 'password' => 'x'])->assertStatus(400);
    api()->withHeaders(['X-Tenant' => 'ghost'])->postJson('/api/v1/auth/login', ['email' => 'a@b.test', 'password' => 'x'])
        ->assertNotFound();
});

it('blocks every request of a suspended tenant', function (): void {
    [$tenant, $owner] = provisionTenant();
    $token = tokenFor($tenant, $owner->email);

    $tenant->update(['status' => TenantStatus::Suspended]);

    api($tenant, $token)->getJson('/api/v1/auth/me')->assertForbidden();
    loginAs($tenant, $owner->email)->assertForbidden();
});

it('revokes the token on logout', function (): void {
    [$tenant, $owner] = provisionTenant();
    $token = tokenFor($tenant, $owner->email);

    api($tenant, $token)->postJson('/api/v1/auth/logout')->assertNoContent();

    expect(PersonalAccessToken::query()->count())->toBe(0);
});

it('throttles repeated failed logins', function (): void {
    [$tenant, $owner] = provisionTenant();

    foreach (range(1, 5) as $_) {
        loginAs($tenant, $owner->email, 'bad-password')->assertUnauthorized();
    }

    loginAs($tenant, $owner->email, 'bad-password')->assertStatus(429);
});

it('returns problem+json errors', function (): void {
    [$tenant] = provisionTenant();

    api($tenant)->getJson('/api/v1/users')
        ->assertUnauthorized()
        ->assertJsonPath('status', 401)
        ->assertJsonStructure(['type', 'title', 'status']);
});
