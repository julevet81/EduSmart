<?php

declare(strict_types=1);

use App\Models\Level;

it('seeds the Algerian curriculum for every new tenant', function (): void {
    [$tenant, $token] = ownerSession();

    $levels = api($tenant, $token)->getJson('/api/v1/levels?per_page=100')->assertOk()->json('data');

    expect($levels)->toHaveCount(13)
        ->and(collect($levels)->pluck('code'))->toContain('PREP', '5AP', '4AM', '3AS')
        ->and(api($tenant, $token)->getJson('/api/v1/tracks')->json('data'))->toHaveCount(8)
        ->and(api($tenant, $token)->getJson('/api/v1/subjects?per_page=100')->json('data'))->toHaveCount(15);
});

it('keeps each tenant curriculum separate and editable', function (): void {
    [$a, $tokenA] = ownerSession('school-a');
    [$b] = provisionTenant('school-b');

    api($a, $tokenA)->patchJson('/api/v1/levels/'.inTenant($a, fn () => Level::query()->where('code', '1AP')->value('id')), [
        'name' => 'تحضيري معدّل',
    ])->assertOk();

    expect(inTenant($b, fn () => Level::query()->where('code', '1AP')->value('name')))->not->toBe('تحضيري معدّل');
});

it('refuses to delete a level that is in use', function (): void {
    [$tenant, $token] = ownerSession();
    $year = makeYear($tenant);
    $group = makeGroup($tenant, $year);

    api($tenant, $token)->deleteJson("/api/v1/levels/{$group->level_id}")->assertStatus(409);
});

it('rejects duplicate level codes inside the same tenant only', function (): void {
    [$a, $tokenA] = ownerSession('school-a');
    [$b, $tokenB] = ownerSession('school-b');
    $payload = ['code' => 'XTRA', 'name' => 'Extra', 'cycle' => 'other'];

    api($a, $tokenA)->postJson('/api/v1/levels', $payload)->assertCreated();
    api($a, $tokenA)->postJson('/api/v1/levels', $payload)->assertStatus(422)->assertJsonValidationErrors('code');
    api($b, $tokenB)->postJson('/api/v1/levels', $payload)->assertCreated();
});
