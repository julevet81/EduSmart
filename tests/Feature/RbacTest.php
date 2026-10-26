<?php

declare(strict_types=1);

use App\Models\User;

function tenantUser(App\Models\Tenant $tenant, string $email, ?string $role = null): User
{
    return inTenant($tenant, function () use ($email, $role): User {
        $user = User::factory()->create(['email' => $email]);
        if ($role !== null) {
            $user->assignRole($role);
        }

        return $user;
    });
}

it('denies a user without permissions', function (): void {
    [$tenant] = provisionTenant();
    tenantUser($tenant, 'nobody@school-a.test');

    api($tenant, tokenFor($tenant, 'nobody@school-a.test'))
        ->getJson('/api/v1/users')
        ->assertForbidden();
});

it('lets a manager read users but not create them with the owner role', function (): void {
    [$tenant] = provisionTenant();
    tenantUser($tenant, 'manager@school-a.test', 'manager');
    $token = tokenFor($tenant, 'manager@school-a.test');

    api($tenant, $token)->getJson('/api/v1/users')->assertOk();

    api($tenant, $token)->postJson('/api/v1/users', [
        'name' => 'Sneaky', 'email' => 'sneaky@school-a.test', 'password' => TEST_PASSWORD, 'roles' => ['owner'],
    ])->assertForbidden();
});

it('lets the owner create a teacher account', function (): void {
    [$tenant, $owner] = provisionTenant();

    api($tenant, tokenFor($tenant, $owner->email))->postJson('/api/v1/users', [
        'name' => 'Teacher One', 'email' => 'Teacher@School-A.test', 'password' => TEST_PASSWORD,
        'phone' => '0555123456', 'roles' => ['teacher'],
    ])->assertCreated()
        ->assertJsonPath('data.email', 'teacher@school-a.test')
        ->assertJsonPath('data.roles.0', 'teacher');
});

it('prevents a manager from editing an owner', function (): void {
    [$tenant, $owner] = provisionTenant();
    tenantUser($tenant, 'manager@school-a.test', 'manager');

    api($tenant, tokenFor($tenant, 'manager@school-a.test'))
        ->patchJson("/api/v1/users/{$owner->id}", ['password' => 'An0ther-Str0ng-Pass!'])
        ->assertForbidden();
});

it('keeps at least one active owner', function (): void {
    [$tenant, $owner] = provisionTenant();

    api($tenant, tokenFor($tenant, $owner->email))
        ->patchJson("/api/v1/users/{$owner->id}", ['roles' => ['manager']])
        ->assertStatus(422);
});

it('validates Algerian phone numbers', function (): void {
    [$tenant, $owner] = provisionTenant();

    api($tenant, tokenFor($tenant, $owner->email))->postJson('/api/v1/users', [
        'name' => 'Bad Phone', 'email' => 'bad@school-a.test', 'password' => TEST_PASSWORD, 'phone' => '12345',
    ])->assertStatus(422)->assertJsonValidationErrors('phone');
});
