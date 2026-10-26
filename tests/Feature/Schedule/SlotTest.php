<?php

declare(strict_types=1);

/** @return array{0: App\Models\Tenant, 1: string, 2: App\Models\Group, 3: App\Models\Teacher, 4: App\Models\Room} */
function slotWorld(string $slug = 'school-a'): array
{
    [$tenant, $token] = ownerSession($slug);
    $year = makeCurrentYear($tenant);

    return [$tenant, $token, makeGroup($tenant, $year, 'G1'), makeTeacher($tenant), makeRoom($tenant)];
}

it('creates a weekly slot that defaults to the academic year period', function (): void {
    [$tenant, $token, $group, $teacher, $room] = slotWorld();

    api($tenant, $token)->postJson('/api/v1/schedule-slots', slotBody($tenant, $group, $teacher, $room))
        ->assertCreated()
        ->assertJsonPath('data.starts_at', '08:00')
        ->assertJsonPath('data.ends_at', '09:30')
        ->assertJsonPath('data.valid_from', now()->subMonths(2)->toDateString())
        ->assertJsonPath('data.group.name', 'G1');
});

it('blocks teacher, room and group clashes but allows back-to-back lessons', function (): void {
    [$tenant, $token, $g1, $teacher, $room] = slotWorld();
    $g2 = makeGroup($tenant, AcademicYearOf($g1), 'G2');
    $teacher2 = makeTeacher($tenant, null, 'Other');
    $room2 = makeRoom($tenant, 'R2');

    api($tenant, $token)->postJson('/api/v1/schedule-slots', slotBody($tenant, $g1, $teacher, $room))->assertCreated();

    // same teacher, overlapping time
    api($tenant, $token)->postJson('/api/v1/schedule-slots', slotBody($tenant, $g2, $teacher, $room2, ['starts_at' => '09:00', 'ends_at' => '10:00']))
        ->assertStatus(422)->assertJsonValidationErrors('teacher_id');

    // same room
    api($tenant, $token)->postJson('/api/v1/schedule-slots', slotBody($tenant, $g2, $teacher2, $room, ['starts_at' => '08:30', 'ends_at' => '09:00']))
        ->assertStatus(422)->assertJsonValidationErrors('room_id');

    // same group
    api($tenant, $token)->postJson('/api/v1/schedule-slots', slotBody($tenant, $g1, $teacher2, $room2, ['starts_at' => '09:00', 'ends_at' => '10:00']))
        ->assertStatus(422)->assertJsonValidationErrors('group_id');

    // starts exactly when the first one ends: allowed
    api($tenant, $token)->postJson('/api/v1/schedule-slots', slotBody($tenant, $g1, $teacher, $room, ['starts_at' => '09:30', 'ends_at' => '11:00']))
        ->assertCreated();

    // another weekday: allowed
    api($tenant, $token)->postJson('/api/v1/schedule-slots', slotBody($tenant, $g1, $teacher, $room, ['weekday' => 2]))->assertCreated();
});

it('does not mix clashes between tenants', function (): void {
    [$a, $tokenA, $groupA, $teacherA, $roomA] = slotWorld('school-a');
    [$b, $tokenB, $groupB, $teacherB, $roomB] = slotWorld('school-b');

    api($a, $tokenA)->postJson('/api/v1/schedule-slots', slotBody($a, $groupA, $teacherA, $roomA))->assertCreated();
    api($b, $tokenB)->postJson('/api/v1/schedule-slots', slotBody($b, $groupB, $teacherB, $roomB))->assertCreated();

    // tenant B cannot reference tenant A resources at all (ids differ: A has two teachers, B only one)
    $extraTeacherA = makeTeacher($a, null, 'Extra');
    api($b, $tokenB)->postJson('/api/v1/schedule-slots', slotBody($b, $groupB, $extraTeacherA, $roomB, ['weekday' => 3]))
        ->assertStatus(422)->assertJsonValidationErrors('teacher_id');
});

it('validates times and the validity period', function (): void {
    [$tenant, $token, $group, $teacher, $room] = slotWorld();

    api($tenant, $token)->postJson('/api/v1/schedule-slots', slotBody($tenant, $group, $teacher, $room, ['starts_at' => '10:00', 'ends_at' => '09:00']))
        ->assertStatus(422)->assertJsonValidationErrors('ends_at');

    api($tenant, $token)->postJson('/api/v1/schedule-slots', slotBody($tenant, $group, $teacher, $room, [
        'valid_from' => now()->subYear()->toDateString(),
    ]))->assertStatus(422)->assertJsonValidationErrors('valid_from');

    api($tenant, $token)->postJson('/api/v1/schedule-slots', slotBody($tenant, $group, $teacher, $room, ['weekday' => 9]))
        ->assertStatus(422)->assertJsonValidationErrors('weekday');
});

it('frees the time when a slot is deactivated and checks clashes on update', function (): void {
    [$tenant, $token, $group, $teacher, $room] = slotWorld();
    $g2 = makeGroup($tenant, AcademicYearOf($group), 'G2');
    $room2 = makeRoom($tenant, 'R2');

    $first = api($tenant, $token)->postJson('/api/v1/schedule-slots', slotBody($tenant, $group, $teacher, $room))->json('data.id');

    // editing a slot does not clash with itself
    api($tenant, $token)->patchJson("/api/v1/schedule-slots/{$first}", ['room_id' => $room2->id])->assertOk();

    $second = api($tenant, $token)->postJson('/api/v1/schedule-slots', slotBody($tenant, $g2, makeTeacher($tenant, null, 'T2'), $room, ['starts_at' => '10:00', 'ends_at' => '11:00']))
        ->assertCreated()->json('data.id');

    // moving the second one onto the first one's teacher and time is refused
    api($tenant, $token)->patchJson("/api/v1/schedule-slots/{$second}", ['teacher_id' => $teacher->id, 'starts_at' => '09:00', 'ends_at' => '10:30'])
        ->assertStatus(422)->assertJsonValidationErrors('teacher_id');

    // after deactivating the first, the same change is accepted
    api($tenant, $token)->patchJson("/api/v1/schedule-slots/{$first}", ['is_active' => false])->assertOk();
    api($tenant, $token)->patchJson("/api/v1/schedule-slots/{$second}", ['teacher_id' => $teacher->id, 'starts_at' => '09:00', 'ends_at' => '10:30'])
        ->assertOk();
});

it('manages holidays and validates their dates', function (): void {
    [$tenant, $token] = ownerSession();

    api($tenant, $token)->postJson('/api/v1/holidays', ['name' => 'Aïd', 'starts_on' => '2027-03-10', 'ends_on' => '2027-03-05'])
        ->assertStatus(422)->assertJsonValidationErrors('ends_on');

    api($tenant, $token)->postJson('/api/v1/holidays', ['name' => 'Aïd', 'starts_on' => '2027-03-05', 'ends_on' => '2027-03-10'])
        ->assertCreated()->assertJsonPath('data.starts_on', '2027-03-05');

    api($tenant, $token)->getJson('/api/v1/holidays?from=2027-03-01&to=2027-03-31')->assertOk()->assertJsonCount(1, 'data');
});

function AcademicYearOf(App\Models\Group $group): App\Models\AcademicYear
{
    return App\Models\AcademicYear::query()->withoutGlobalScopes()->findOrFail($group->academic_year_id);
}
