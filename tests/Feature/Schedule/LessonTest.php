<?php

declare(strict_types=1);

use App\Models\Lesson;

/** @return array{0: App\Models\Tenant, 1: string, 2: App\Models\Group, 3: App\Models\Teacher, 4: App\Models\Room, 5: int} slot id last */
function lessonWorld(): array
{
    [$tenant, $token] = ownerSession();
    $group = makeGroup($tenant, makeCurrentYear($tenant), 'G1');
    $teacher = makeTeacher($tenant);
    $room = makeRoom($tenant);

    $slotId = api($tenant, $token)->postJson('/api/v1/schedule-slots', slotBody($tenant, $group, $teacher, $room))
        ->assertCreated()->json('data.id');

    return [$tenant, $token, $group, $teacher, $room, $slotId];
}

it('generates lessons from slots, skipping holidays, and is idempotent', function (): void {
    [$tenant, $token] = lessonWorld();
    $monday = nextMonday();

    api($tenant, $token)->postJson('/api/v1/holidays', [
        'name' => 'Holiday', 'starts_on' => $monday->addDays(7)->toDateString(), 'ends_on' => $monday->addDays(7)->toDateString(),
    ])->assertCreated();

    $window = ['from' => $monday->toDateString(), 'to' => $monday->addDays(20)->toDateString()];

    api($tenant, $token)->postJson('/api/v1/lessons/generate', $window)
        ->assertOk()->assertJsonPath('created', 2)->assertJsonPath('skipped_holidays', 1);

    api($tenant, $token)->postJson('/api/v1/lessons/generate', $window)
        ->assertOk()->assertJsonPath('created', 0)->assertJsonPath('skipped_existing', 2);

    $lessons = api($tenant, $token)->getJson("/api/v1/lessons?from={$window['from']}&to={$window['to']}")->json('data');
    expect($lessons)->toHaveCount(2)
        ->and($lessons[0]['date'])->toBe($monday->toDateString())
        ->and($lessons[0]['starts_at'])->toBe('08:00')
        ->and($lessons[0]['group']['name'])->toBe('G1');
});

it('limits the generation window', function (): void {
    [$tenant, $token] = lessonWorld();

    api($tenant, $token)->postJson('/api/v1/lessons/generate', [
        'from' => now()->toDateString(), 'to' => now()->addDays(100)->toDateString(),
    ])->assertStatus(422);
});

it('skips and reports an occurrence that clashes with a make-up lesson', function (): void {
    [$tenant, $token, $group, $teacher] = lessonWorld();
    $monday = nextMonday();
    $other = makeGroup($tenant, App\Models\AcademicYear::query()->withoutGlobalScopes()->findOrFail($group->academic_year_id), 'G2');

    makeLesson($tenant, $other, $teacher, $monday->addDays(14)->toDateString(), '08:30', '09:30', null, ['kind' => 'makeup', 'is_customized' => true]);

    api($tenant, $token)->postJson('/api/v1/lessons/generate', ['from' => $monday->toDateString(), 'to' => $monday->addDays(20)->toDateString()])
        ->assertOk()
        ->assertJsonPath('created', 2)
        ->assertJsonCount(1, 'skipped_conflicts')
        ->assertJsonPath('skipped_conflicts.0.date', $monday->addDays(14)->toDateString())
        ->assertJsonPath('skipped_conflicts.0.fields.0', 'teacher_id');
});

it('adds a make-up lesson only when nothing clashes', function (): void {
    [$tenant, $token, $group, $teacher, $room] = lessonWorld();
    $monday = nextMonday();
    $body = fn (array $o = []) => array_merge([
        'group_id' => $group->id, 'subject_id' => subjectId($tenant), 'teacher_id' => $teacher->id, 'room_id' => $room->id,
        'date' => $monday->toDateString(), 'starts_at' => '14:00', 'ends_at' => '15:00',
    ], $o);

    api($tenant, $token)->postJson('/api/v1/lessons/generate', ['from' => $monday->toDateString(), 'to' => $monday->toDateString()])->assertOk();

    api($tenant, $token)->postJson('/api/v1/lessons', $body(['starts_at' => '09:00', 'ends_at' => '10:00']))
        ->assertStatus(422)->assertJsonValidationErrors(['teacher_id', 'room_id', 'group_id']);

    api($tenant, $token)->postJson('/api/v1/lessons', $body())
        ->assertCreated()->assertJsonPath('data.kind', 'makeup')->assertJsonPath('data.is_customized', true);

    api($tenant, $token)->postJson('/api/v1/lessons', $body(['date' => now()->addYears(3)->toDateString()]))
        ->assertStatus(422)->assertJsonValidationErrors('date');
});

it('moves and cancels a lesson with the same clash rules', function (): void {
    [$tenant, $token, $group, $teacher, $room] = lessonWorld();
    $room2 = makeRoom($tenant, 'R2');
    $date = nextMonday()->toDateString();
    $lesson = makeLesson($tenant, $group, $teacher, $date, '08:00', '09:30', $room);
    $busy = makeLesson($tenant, makeGroup($tenant, App\Models\AcademicYear::query()->withoutGlobalScopes()->findOrFail($group->academic_year_id), 'G9'), makeTeacher($tenant, null, 'T9'), $date, '10:00', '11:00', $room2);

    api($tenant, $token)->patchJson("/api/v1/lessons/{$lesson->id}", ['room_id' => $room2->id, 'starts_at' => '10:30', 'ends_at' => '11:30'])
        ->assertStatus(422)->assertJsonValidationErrors('room_id');

    api($tenant, $token)->patchJson("/api/v1/lessons/{$lesson->id}", ['room_id' => $room2->id])
        ->assertOk()->assertJsonPath('data.room.name', 'R2')->assertJsonPath('data.is_customized', true);

    api($tenant, $token)->postJson("/api/v1/lessons/{$lesson->id}/cancel", ['note' => 'Teacher ill'])
        ->assertOk()->assertJsonPath('data.status', 'cancelled');
    api($tenant, $token)->postJson("/api/v1/lessons/{$lesson->id}/cancel")->assertStatus(422);
    api($tenant, $token)->patchJson("/api/v1/lessons/{$lesson->id}", ['date' => $date])->assertStatus(422);

    // a cancelled lesson frees its teacher and room
    api($tenant, $token)->patchJson("/api/v1/lessons/{$busy->id}", ['starts_at' => '08:00', 'ends_at' => '09:00'])->assertOk();
});

it('replaces only untouched future lessons when a slot changes', function (): void {
    [$tenant, $token, , , , $slotId] = lessonWorld();
    $monday = nextMonday();
    $window = ['from' => $monday->toDateString(), 'to' => $monday->addDays(20)->toDateString()];

    api($tenant, $token)->postJson('/api/v1/lessons/generate', $window)->assertJsonPath('created', 3);

    $ids = api($tenant, $token)->getJson("/api/v1/lessons?from={$window['from']}&to={$window['to']}")->json('data.*.id');
    api($tenant, $token)->patchJson("/api/v1/lessons/{$ids[0]}", ['note' => 'x', 'room_id' => null])->assertOk(); // customized

    api($tenant, $token)->patchJson("/api/v1/schedule-slots/{$slotId}", ['starts_at' => '10:00', 'ends_at' => '11:30'])->assertOk();

    $left = api($tenant, $token)->getJson("/api/v1/lessons?from={$window['from']}&to={$window['to']}")->json('data');
    expect($left)->toHaveCount(1)->and($left[0]['id'])->toBe($ids[0]);

    api($tenant, $token)->postJson('/api/v1/lessons/generate', $window)->assertJsonPath('created', 2)->assertJsonPath('skipped_existing', 1);
});

it('shows a teacher only their own lessons', function (): void {
    [$tenant, $ownerToken, $group] = lessonWorld();
    [$teacherToken, $mine] = teacherSession($tenant);
    $other = makeTeacher($tenant, null, 'Other');
    $today = now()->toDateString();

    $own = makeLesson($tenant, $group, $mine, $today, '13:00', '14:00');
    $foreign = makeLesson($tenant, $group, $other, $today, '15:00', '16:00');

    $ids = api($tenant, $teacherToken)->getJson("/api/v1/lessons?from={$today}&to={$today}")->assertOk()->json('data.*.id');
    expect($ids)->toBe([$own->id]);

    api($tenant, $teacherToken)->getJson("/api/v1/lessons/{$own->id}")->assertOk();
    api($tenant, $teacherToken)->getJson("/api/v1/lessons/{$foreign->id}")->assertNotFound();

    // the full timetable stays with schedule.view holders
    expect(api($tenant, $ownerToken)->getJson("/api/v1/lessons?from={$today}&to={$today}")->json('data'))->toHaveCount(2);

    // and a teacher can never change the timetable
    api($tenant, $teacherToken)->postJson("/api/v1/lessons/{$own->id}/cancel")->assertForbidden();
    api($tenant, $teacherToken)->postJson('/api/v1/lessons/generate', ['from' => $today, 'to' => $today])->assertForbidden();
});

it('never reveals lessons of another tenant', function (): void {
    [$a, $tokenA] = ownerSession('school-a');
    [$b] = provisionTenant('school-b');
    $groupB = makeGroup($b, makeCurrentYear($b));
    $lessonB = makeLesson($b, $groupB, makeTeacher($b), now()->toDateString());

    api($a, $tokenA)->getJson("/api/v1/lessons/{$lessonB->id}")->assertNotFound();
    api($a, $tokenA)->patchJson("/api/v1/lessons/{$lessonB->id}", ['note' => 'x'])->assertNotFound();
    expect(Lesson::query()->withoutGlobalScopes()->count())->toBe(1);
});
