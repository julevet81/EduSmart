<?php

declare(strict_types=1);

use App\Models\AuditLog;

it('records user creation with tenant, actor and without secrets', function (): void {
    [$tenant, $owner] = provisionTenant();

    api($tenant, tokenFor($tenant, $owner->email))->postJson('/api/v1/users', [
        'name' => 'Teacher One', 'email' => 'teacher@school-a.test', 'password' => TEST_PASSWORD,
    ])->assertCreated();

    $log = AuditLog::query()
        ->where('event', 'user.created')
        ->where('tenant_id', $tenant->id)
        ->where('new_values->email', 'teacher@school-a.test')
        ->firstOrFail();

    expect($log->actor_type)->toBe('user')
        ->and($log->actor_id)->toBe((string) $owner->id)
        ->and($log->new_values)->not->toHaveKey('password');
});

it('records failed logins', function (): void {
    [$tenant, $owner] = provisionTenant();

    loginAs($tenant, $owner->email, 'bad-password')->assertUnauthorized();

    expect(AuditLog::query()->where('event', 'auth.login_failed')->where('tenant_id', $tenant->id)->exists())->toBeTrue();
});

it('is immutable', function (): void {
    provisionTenant();
    $log = AuditLog::query()->firstOrFail();

    expect(fn () => $log->update(['event' => 'tampered']))->toThrow(LogicException::class);
    expect(fn () => $log->delete())->toThrow(LogicException::class);
});

it('exposes only the logs of the current tenant', function (): void {
    [$a, $ownerA] = provisionTenant('school-a');
    [$b] = provisionTenant('school-b');

    $response = api($a, tokenFor($a, $ownerA->email))->getJson('/api/v1/audit-logs')->assertOk();

    $ids = collect($response->json('data'))->pluck('id');
    $foreign = AuditLog::query()->where('tenant_id', $b->id)->pluck('id');

    expect($ids)->not->toBeEmpty()
        ->and($ids->intersect($foreign))->toBeEmpty();
});
