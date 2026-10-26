<?php

declare(strict_types=1);

use App\Models\Level;

it('keeps a single current academic year', function (): void {
    [$tenant, $token] = ownerSession();
    $old = makeYear($tenant, '2025/2026');

    api($tenant, $token)->postJson('/api/v1/academic-years', [
        'name' => '2026/2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30', 'is_current' => true,
    ])->assertCreated();

    $current = api($tenant, $token)->getJson('/api/v1/academic-years')->json('data');

    expect(collect($current)->where('is_current', true))->toHaveCount(1)
        ->and(collect($current)->firstWhere('id', $old->id)['is_current'])->toBeFalse();
});

it('validates terms against the academic year', function (): void {
    [$tenant, $token] = ownerSession();
    $year = makeYear($tenant);

    $term = ['number' => 1, 'name' => 'الفصل الأول', 'starts_on' => '2026-09-01', 'ends_on' => '2026-12-15'];

    api($tenant, $token)->postJson("/api/v1/academic-years/{$year->id}/terms", $term)->assertCreated();
    api($tenant, $token)->postJson("/api/v1/academic-years/{$year->id}/terms", $term)
        ->assertStatus(422)->assertJsonValidationErrors('number');
    api($tenant, $token)->postJson("/api/v1/academic-years/{$year->id}/terms", [
        'number' => 2, 'name' => 'T2', 'starts_on' => '2027-01-01', 'ends_on' => '2027-09-30',
    ])->assertStatus(422)->assertJsonValidationErrors('ends_on');
    api($tenant, $token)->postJson("/api/v1/academic-years/{$year->id}/terms", [
        'number' => 4, 'name' => 'T4', 'starts_on' => '2027-01-01', 'ends_on' => '2027-03-01',
    ])->assertStatus(422)->assertJsonValidationErrors('number');
});

it('creates groups only from the tenant own reference data', function (): void {
    [$a, $tokenA] = ownerSession('school-a');
    [$b] = provisionTenant('school-b');
    $year = makeYear($a);

    $ownLevel = inTenant($a, fn () => Level::query()->where('code', '1AM')->value('id'));
    $foreignLevel = inTenant($b, fn () => Level::query()->where('code', '1AM')->value('id'));

    api($a, $tokenA)->postJson('/api/v1/groups', [
        'academic_year_id' => $year->id, 'level_id' => $ownLevel, 'name' => '1AM-1', 'capacity' => 30,
    ])->assertCreated()->assertJsonPath('data.level.code', '1AM');

    api($a, $tokenA)->postJson('/api/v1/groups', [
        'academic_year_id' => $year->id, 'level_id' => $foreignLevel, 'name' => '1AM-2',
    ])->assertStatus(422)->assertJsonValidationErrors('level_id');
});

it('rejects a duplicate group name in the same year', function (): void {
    [$tenant, $token] = ownerSession();
    $year = makeYear($tenant);
    $group = makeGroup($tenant, $year, 'A');

    api($tenant, $token)->postJson('/api/v1/groups', [
        'academic_year_id' => $year->id, 'level_id' => $group->level_id, 'name' => 'A',
    ])->assertStatus(422)->assertJsonValidationErrors('name');
});

it('creates a teacher with subjects and rejects foreign subjects', function (): void {
    [$a, $tokenA] = ownerSession('school-a');
    [$b, $tokenB] = ownerSession('school-b');

    $subjectsA = api($a, $tokenA)->getJson('/api/v1/subjects?per_page=3')->json('data');
    $subjectsB = api($b, $tokenB)->getJson('/api/v1/subjects?per_page=1')->json('data');

    api($a, $tokenA)->postJson('/api/v1/teachers', [
        'first_name' => 'Karim', 'last_name' => 'Haddad', 'phone' => '0555123456',
        'subject_ids' => [$subjectsA[0]['id'], $subjectsA[1]['id']],
    ])->assertCreated()->assertJsonCount(2, 'data.subjects');

    api($a, $tokenA)->postJson('/api/v1/teachers', [
        'first_name' => 'X', 'last_name' => 'Y', 'subject_ids' => [$subjectsB[0]['id']],
    ])->assertStatus(422)->assertJsonValidationErrors('subject_ids.0');
});
