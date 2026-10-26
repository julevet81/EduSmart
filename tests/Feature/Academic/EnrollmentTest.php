<?php

declare(strict_types=1);

it('enrolls a student and shows the current group', function (): void {
    [$tenant, $token] = ownerSession();
    $year = makeYear($tenant);
    $group = makeGroup($tenant, $year);
    $student = makeStudent($tenant);

    api($tenant, $token)->postJson('/api/v1/enrollments', ['student_id' => $student->id, 'group_id' => $group->id])
        ->assertCreated()->assertJsonPath('data.status', 'active')->assertJsonPath('data.academic_year_id', $year->id);

    api($tenant, $token)->getJson("/api/v1/students/{$student->id}")
        ->assertJsonPath('data.current_enrollment.group_id', $group->id);
    api($tenant, $token)->getJson("/api/v1/groups/{$group->id}")->assertJsonPath('data.students_count', 1);
});

it('allows only one active enrollment per student per year', function (): void {
    [$tenant, $token] = ownerSession();
    $year = makeYear($tenant);
    $g1 = makeGroup($tenant, $year, 'G1');
    $g2 = makeGroup($tenant, $year, 'G2');
    $student = makeStudent($tenant);

    api($tenant, $token)->postJson('/api/v1/enrollments', ['student_id' => $student->id, 'group_id' => $g1->id])->assertCreated();
    api($tenant, $token)->postJson('/api/v1/enrollments', ['student_id' => $student->id, 'group_id' => $g2->id])->assertStatus(422);
});

it('refuses to exceed the group capacity', function (): void {
    [$tenant, $token] = ownerSession();
    $group = makeGroup($tenant, makeYear($tenant), 'Small', capacity: 1);

    api($tenant, $token)->postJson('/api/v1/enrollments', ['student_id' => makeStudent($tenant, 'A')->id, 'group_id' => $group->id])->assertCreated();
    api($tenant, $token)->postJson('/api/v1/enrollments', ['student_id' => makeStudent($tenant, 'B')->id, 'group_id' => $group->id])
        ->assertStatus(422)->assertJsonPath('detail', 'This group is full.');
});

it('transfers a student and keeps the history', function (): void {
    [$tenant, $token] = ownerSession();
    $year = makeYear($tenant);
    $g1 = makeGroup($tenant, $year, 'G1');
    $g2 = makeGroup($tenant, $year, 'G2');
    $student = makeStudent($tenant);

    $first = api($tenant, $token)->postJson('/api/v1/enrollments', ['student_id' => $student->id, 'group_id' => $g1->id])->json('data.id');

    api($tenant, $token)->postJson("/api/v1/enrollments/{$first}/transfer", ['group_id' => $g2->id])
        ->assertOk()->assertJsonPath('data.group_id', $g2->id)->assertJsonPath('data.status', 'active');

    $history = api($tenant, $token)->getJson("/api/v1/enrollments?student_id={$student->id}")->json('data');
    expect($history)->toHaveCount(2)
        ->and(collect($history)->pluck('status')->sort()->values()->all())->toBe(['active', 'transferred']);
});

it('refuses a transfer to a group of another academic year', function (): void {
    [$tenant, $token] = ownerSession();
    $g1 = makeGroup($tenant, makeYear($tenant, '2026/2027'), 'G1');
    $g2 = makeGroup($tenant, makeYear($tenant, '2027/2028'), 'G1');
    $student = makeStudent($tenant);

    $id = api($tenant, $token)->postJson('/api/v1/enrollments', ['student_id' => $student->id, 'group_id' => $g1->id])->json('data.id');

    api($tenant, $token)->postJson("/api/v1/enrollments/{$id}/transfer", ['group_id' => $g2->id])->assertStatus(422);
});

it('closes an enrollment and then allows a new one in the same year', function (): void {
    [$tenant, $token] = ownerSession();
    $year = makeYear($tenant);
    $group = makeGroup($tenant, $year);
    $student = makeStudent($tenant);

    $id = api($tenant, $token)->postJson('/api/v1/enrollments', ['student_id' => $student->id, 'group_id' => $group->id])->json('data.id');

    api($tenant, $token)->postJson("/api/v1/enrollments/{$id}/close", ['status' => 'withdrawn'])
        ->assertOk()->assertJsonPath('data.status', 'withdrawn');
    api($tenant, $token)->postJson("/api/v1/enrollments/{$id}/close", ['status' => 'withdrawn'])->assertStatus(422);
    api($tenant, $token)->postJson('/api/v1/enrollments', ['student_id' => $student->id, 'group_id' => $group->id])->assertCreated();
});

it('cannot enroll across tenants', function (): void {
    [$a, $tokenA] = ownerSession('school-a');
    [$b] = provisionTenant('school-b');
    $groupA = makeGroup($a, makeYear($a));
    $studentB = makeStudent($b);
    $groupB = makeGroup($b, makeYear($b));

    api($a, $tokenA)->postJson('/api/v1/enrollments', ['student_id' => $studentB->id, 'group_id' => $groupA->id])
        ->assertStatus(422)->assertJsonValidationErrors('student_id');
    api($a, $tokenA)->postJson('/api/v1/enrollments', ['student_id' => makeStudent($a)->id, 'group_id' => $groupB->id])
        ->assertStatus(422)->assertJsonValidationErrors('group_id');
});

it('filters students by group and level', function (): void {
    [$tenant, $token] = ownerSession();
    $year = makeYear($tenant);
    $group = makeGroup($tenant, $year);
    $in = makeStudent($tenant, 'Inside');
    makeStudent($tenant, 'Outside');

    api($tenant, $token)->postJson('/api/v1/enrollments', ['student_id' => $in->id, 'group_id' => $group->id])->assertCreated();

    api($tenant, $token)->getJson("/api/v1/students?group_id={$group->id}")
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.first_name', 'Inside');
    api($tenant, $token)->getJson("/api/v1/students?level_id={$group->level_id}")->assertJsonCount(1, 'data');
});

it('lets only authorized roles manage enrollments', function (): void {
    [$tenant] = ownerSession();
    $group = makeGroup($tenant, makeYear($tenant));
    $student = makeStudent($tenant);
    $body = ['student_id' => $student->id, 'group_id' => $group->id];

    api($tenant, roleToken($tenant, 'accountant'))->postJson('/api/v1/enrollments', $body)->assertForbidden();
    api($tenant, roleToken($tenant, 'secretary'))->postJson('/api/v1/enrollments', $body)->assertCreated();
});
