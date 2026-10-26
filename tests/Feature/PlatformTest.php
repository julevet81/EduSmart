<?php

declare(strict_types=1);

use App\Models\Tenant;

function newTenantPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Ecole Les Lumieres',
        'slug' => 'lumieres',
        'type' => 'private_school',
        'contact_email' => 'contact@lumieres.test',
        'phone' => '0661234567',
        'owner_name' => 'Directeur',
        'owner_email' => 'director@lumieres.test',
        'owner_password' => TEST_PASSWORD,
    ], $overrides);
}

it('lets a platform admin provision a tenant whose owner can then log in', function (): void {
    $token = platformToken();

    api(null, $token)->postJson('/api/v1/platform/tenants', newTenantPayload())
        ->assertCreated()
        ->assertJsonPath('data.slug', 'lumieres')
        ->assertJsonPath('data.status', 'trial');

    $tenant = Tenant::query()->where('slug', 'lumieres')->firstOrFail();

    loginAs($tenant, 'director@lumieres.test')->assertOk()->assertJsonPath('user.roles.0', 'owner');
});

it('rejects duplicate and reserved slugs', function (): void {
    $token = platformToken();

    api(null, $token)->postJson('/api/v1/platform/tenants', newTenantPayload())->assertCreated();
    api(null, $token)->postJson('/api/v1/platform/tenants', newTenantPayload(['owner_email' => 'b@b.test']))
        ->assertStatus(422)->assertJsonValidationErrors('slug');
    api(null, $token)->postJson('/api/v1/platform/tenants', newTenantPayload(['slug' => 'admin']))
        ->assertStatus(422)->assertJsonValidationErrors('slug');
});

it('can suspend a tenant', function (): void {
    $token = platformToken();
    [$tenant, $owner] = provisionTenant();

    api(null, $token)->patchJson("/api/v1/platform/tenants/{$tenant->id}", ['status' => 'suspended'])
        ->assertOk()->assertJsonPath('data.status', 'suspended');

    loginAs($tenant, $owner->email)->assertForbidden();
});

it('keeps platform endpoints closed to tenant users and anonymous callers', function (): void {
    [$tenant, $owner] = provisionTenant();
    $tenantToken = tokenFor($tenant, $owner->email);

    api()->getJson('/api/v1/platform/tenants')->assertUnauthorized();

    $response = api(null, $tenantToken)->getJson('/api/v1/platform/tenants');
    expect($response->status())->toBeIn([401, 403]);
});

it('keeps tenant endpoints closed to platform admins', function (): void {
    [$tenant] = provisionTenant();
    $token = platformToken();

    $response = api($tenant, $token)->getJson('/api/v1/users');
    expect($response->status())->toBeIn([401, 403]);
});
