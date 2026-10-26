<?php

declare(strict_types=1);

use App\Exceptions\TenantContextException;
use App\Models\User;
use Spatie\Permission\Models\Role;

it('lists only the users of the current tenant', function (): void {
    [$a, $ownerA] = provisionTenant('school-a');
    [, $ownerB] = provisionTenant('school-b');

    $token = tokenFor($a, $ownerA->email);

    api($a, $token)->getJson('/api/v1/users')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonFragment(['email' => $ownerA->email])
        ->assertJsonMissing(['email' => $ownerB->email]);
});

it('returns 404 for a user that belongs to another tenant', function (): void {
    [$a, $ownerA] = provisionTenant('school-a');
    [, $ownerB] = provisionTenant('school-b');

    api($a, tokenFor($a, $ownerA->email))
        ->getJson("/api/v1/users/{$ownerB->id}")
        ->assertNotFound();
});

it('rejects a token of tenant A used against tenant B', function (): void {
    [$a, $ownerA] = provisionTenant('school-a');
    [$b] = provisionTenant('school-b');

    $tokenA = tokenFor($a, $ownerA->email);

    $response = api($b, $tokenA)->getJson('/api/v1/auth/me');

    expect($response->status())->toBeIn([401, 403]);
});

it('fails closed when no tenant is resolved', function (): void {
    provisionTenant('school-a');
    app(App\Support\Tenancy\TenantContext::class)->forget();

    expect(User::query()->count())->toBe(0);
    expect(fn () => User::query()->create([
        'name' => 'X', 'email' => 'x@x.test', 'password' => TEST_PASSWORD,
    ]))->toThrow(TenantContextException::class);
});

it('allows the same email in two different tenants', function (): void {
    [$a] = provisionTenant('school-a');
    [$b] = provisionTenant('school-b');

    inTenant($a, fn () => User::factory()->create(['email' => 'same@mail.test']));
    inTenant($b, fn () => User::factory()->create(['email' => 'same@mail.test']));

    expect(inTenant($a, fn () => User::query()->where('email', 'same@mail.test')->count()))->toBe(1);
    expect(inTenant($b, fn () => User::query()->where('email', 'same@mail.test')->count()))->toBe(1);
});

it('refuses to assign a role that belongs to another tenant', function (): void {
    [$a, $ownerA] = provisionTenant('school-a');
    [$b] = provisionTenant('school-b');

    inTenant($b, fn () => Role::query()->create([
        'name' => 'secret-role', 'guard_name' => 'web', 'tenant_id' => $b->id,
    ]));

    api($a, tokenFor($a, $ownerA->email))->postJson('/api/v1/users', [
        'name' => 'New', 'email' => 'new@a.test', 'password' => TEST_PASSWORD,
        'roles' => ['secret-role'],
    ])->assertStatus(422);
});

it('refuses to create a user for another tenant through the context guard', function (): void {
    [$a] = provisionTenant('school-a');
    [$b] = provisionTenant('school-b');

    expect(fn () => inTenant($a, fn () => (new User)->forceFill([
        'tenant_id' => $b->id, 'name' => 'X', 'email' => 'x@x.test', 'password' => TEST_PASSWORD,
    ])->save()))->toThrow(TenantContextException::class);
});
