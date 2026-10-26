<?php

declare(strict_types=1);

use App\Enums\EnrollmentStatus;
use App\Models\Enrollment;
use App\Models\Student;

/**
 * Group with three enrolled students and a lesson today.
 *
 * @return array{0: App\Models\Tenant, 1: string, 2: App\Models\Group, 3: App\Models\Teacher, 4: App\Models\Lesson, 5: list<Student>}
 */
function attendanceWorld(): array
{
    [$tenant, $token] = ownerSession();
    $group = makeGroup($tenant, makeCurrentYear($tenant), 'G1');
    $teacher = makeTeacher($tenant);
    $lesson = makeLesson($tenant, $group, $teacher, now()->toDateString(), '13:00', '14:00');

    $students = [];
    foreach (['Amine', 'Bilal', 'Chakib'] as $name) {
        $student = makeStudent($tenant, $name);
        enrollNow($tenant, $student, $group);
        $students[] = $student;
    }

    return [$tenant, $token, $group, $teacher, $lesson, $students];
}

it('lists everyone enrolled on the lesson date as present by default', function (): void {
    [$tenant, $token, $group, , $lesson] = attendanceWorld();

    // a student who left yesterday and one who joins tomorrow are not on today's roster
    $left = makeStudent($tenant, 'Left');
    inTenant($tenant, fn () => Enrollment::query()->create([
        'student_id' => $left->id, 'group_id' => $group->id, 'academic_year_id' => $group->academic_year_id,
        'status' => EnrollmentStatus::Transferred, 'enrolled_on' => now()->subDays(30)->toDateString(),
        'ended_on' => now()->subDay()->toDateString(),
    ]));
    enrollNow($tenant, makeStudent($tenant, 'Future'), $group, now()->addDay()->toDateString());

    $response = api($tenant, $token)->getJson("/api/v1/lessons/{$lesson->id}/attendance")->assertOk();

    expect($response->json('data'))->toHaveCount(3)
        ->and(collect($response->json('data'))->pluck('status')->unique()->all())->toBe(['present']);
});

it('records absences, marks the lesson completed and replaces the list on resubmission', function (): void {
    [$tenant, $token, , , $lesson, [$amine, $bilal, $chakib]] = attendanceWorld();

    api($tenant, $token)->putJson("/api/v1/lessons/{$lesson->id}/attendance", ['absences' => [
        ['student_id' => $amine->id, 'type' => 'absent', 'reason' => 'Sick'],
        ['student_id' => $bilal->id, 'type' => 'late'],
    ]])->assertOk()->assertJsonPath('lesson.status', 'completed')->assertJsonPath('absences_count', 2);

    $roster = collect(api($tenant, $token)->getJson("/api/v1/lessons/{$lesson->id}/attendance")->json('data'))
        ->mapWithKeys(fn (array $row) => [$row['student']['id'] => $row['status']]);
    expect($roster[$amine->id])->toBe('absent')->and($roster[$bilal->id])->toBe('late')->and($roster[$chakib->id])->toBe('present');

    // resubmitting with only Bilal removes Amine's absence
    api($tenant, $token)->putJson("/api/v1/lessons/{$lesson->id}/attendance", ['absences' => [
        ['student_id' => $bilal->id, 'type' => 'absent'],
    ]])->assertOk();

    expect(api($tenant, $token)->getJson("/api/v1/absences?student_id={$amine->id}")->json('data'))->toBeEmpty()
        ->and(api($tenant, $token)->getJson("/api/v1/absences?student_id={$bilal->id}")->json('data.0.type'))->toBe('absent');

    // everyone present: an empty list is a valid submission
    api($tenant, $token)->putJson("/api/v1/lessons/{$lesson->id}/attendance", ['absences' => []])->assertOk();
    expect(api($tenant, $token)->getJson('/api/v1/absences')->json('data'))->toBeEmpty();
});

it('rejects invalid attendance submissions', function (): void {
    [$tenant, $token, $group, $teacher, $lesson, [$amine]] = attendanceWorld();
    $outsider = makeStudent($tenant, 'Outsider');

    // student not enrolled in the group
    api($tenant, $token)->putJson("/api/v1/lessons/{$lesson->id}/attendance", ['absences' => [
        ['student_id' => $outsider->id, 'type' => 'absent'],
    ]])->assertStatus(422);

    // duplicated student / bad type / missing list
    api($tenant, $token)->putJson("/api/v1/lessons/{$lesson->id}/attendance", ['absences' => [
        ['student_id' => $amine->id, 'type' => 'absent'], ['student_id' => $amine->id, 'type' => 'late'],
    ]])->assertStatus(422);
    api($tenant, $token)->putJson("/api/v1/lessons/{$lesson->id}/attendance", ['absences' => [
        ['student_id' => $amine->id, 'type' => 'sleeping'],
    ]])->assertStatus(422);
    api($tenant, $token)->putJson("/api/v1/lessons/{$lesson->id}/attendance", [])->assertStatus(422);

    // future lesson
    $future = makeLesson($tenant, $group, $teacher, now()->addDays(2)->toDateString(), '13:00', '14:00');
    api($tenant, $token)->putJson("/api/v1/lessons/{$future->id}/attendance", ['absences' => []])->assertStatus(422);

    // cancelled lesson
    $cancelled = makeLesson($tenant, $group, $teacher, now()->toDateString(), '16:00', '17:00', null, ['status' => 'cancelled']);
    api($tenant, $token)->putJson("/api/v1/lessons/{$cancelled->id}/attendance", ['absences' => []])->assertStatus(422);
});

it('lets a teacher take attendance of their own lessons only', function (): void {
    [$tenant, , $group, , , [$amine]] = attendanceWorld();
    [$teacherToken, $mine] = teacherSession($tenant);
    $own = makeLesson($tenant, $group, $mine, now()->toDateString(), '17:00', '18:00');
    $foreign = makeLesson($tenant, $group, makeTeacher($tenant, null, 'Other'), now()->toDateString(), '18:00', '19:00');

    api($tenant, $teacherToken)->getJson("/api/v1/lessons/{$own->id}/attendance")->assertOk()->assertJsonCount(3, 'data');
    api($tenant, $teacherToken)->putJson("/api/v1/lessons/{$own->id}/attendance", ['absences' => [
        ['student_id' => $amine->id, 'type' => 'absent'],
    ]])->assertOk();

    api($tenant, $teacherToken)->getJson("/api/v1/lessons/{$foreign->id}/attendance")->assertNotFound();
    api($tenant, $teacherToken)->putJson("/api/v1/lessons/{$foreign->id}/attendance", ['absences' => []])->assertNotFound();

    // a teacher sees no student records, no absence reports and cannot justify
    api($tenant, $teacherToken)->getJson('/api/v1/students')->assertForbidden();
    api($tenant, $teacherToken)->getJson('/api/v1/absences')->assertForbidden();
});

it('justifies an absence and exposes it in the filters', function (): void {
    [$tenant, $token, , , $lesson, [$amine]] = attendanceWorld();
    $secretary = roleToken($tenant, 'secretary');
    $teacherToken = teacherSession($tenant)[0];

    api($tenant, $token)->putJson("/api/v1/lessons/{$lesson->id}/attendance", ['absences' => [
        ['student_id' => $amine->id, 'type' => 'absent'],
    ]])->assertOk();
    $absenceId = api($tenant, $token)->getJson('/api/v1/absences')->json('data.0.id');

    api($tenant, $teacherToken)->patchJson("/api/v1/absences/{$absenceId}/justify", ['is_justified' => true])->assertForbidden();

    api($tenant, $secretary)->patchJson("/api/v1/absences/{$absenceId}/justify", ['is_justified' => true, 'justification' => 'Medical certificate'])
        ->assertOk()->assertJsonPath('data.is_justified', true);

    expect(api($tenant, $token)->getJson('/api/v1/absences?is_justified=1')->json('data'))->toHaveCount(1)
        ->and(api($tenant, $token)->getJson('/api/v1/absences?is_justified=0')->json('data'))->toBeEmpty();
});

it('summarizes the attendance of a student over a period', function (): void {
    [$tenant, $token, $group, $teacher, $today, [$amine]] = attendanceWorld();
    $yesterday = makeLesson($tenant, $group, $teacher, now()->subDay()->toDateString(), '13:00', '14:00');

    api($tenant, $token)->putJson("/api/v1/lessons/{$today->id}/attendance", ['absences' => [
        ['student_id' => $amine->id, 'type' => 'absent'],
    ]])->assertOk();
    api($tenant, $token)->putJson("/api/v1/lessons/{$yesterday->id}/attendance", ['absences' => [
        ['student_id' => $amine->id, 'type' => 'late'],
    ]])->assertOk();

    $from = now()->subDays(10)->toDateString();
    $to = now()->toDateString();

    api($tenant, $token)->getJson("/api/v1/students/{$amine->id}/attendance-summary?from={$from}&to={$to}")
        ->assertOk()
        ->assertJsonPath('lessons_count', 2)
        ->assertJsonPath('absent', 1)
        ->assertJsonPath('late', 1)
        ->assertJsonPath('unjustified_absences', 1)
        ->assertJsonPath('attendance_rate', 50);

    api($tenant, $token)->getJson("/api/v1/students/{$amine->id}/attendance-summary?from={$to}&to={$from}")->assertStatus(422);
});

it('keeps attendance of another tenant out of reach', function (): void {
    [$a, $tokenA] = ownerSession('school-a');
    [$b] = provisionTenant('school-b');
    $groupB = makeGroup($b, makeCurrentYear($b));
    $lessonB = makeLesson($b, $groupB, makeTeacher($b), now()->toDateString());
    $studentB = makeStudent($b);
    enrollNow($b, $studentB, $groupB);

    api($a, $tokenA)->getJson("/api/v1/lessons/{$lessonB->id}/attendance")->assertNotFound();
    api($a, $tokenA)->putJson("/api/v1/lessons/{$lessonB->id}/attendance", ['absences' => []])->assertNotFound();
    api($a, $tokenA)->getJson("/api/v1/students/{$studentB->id}/attendance-summary?from=2026-01-01&to=2026-12-31")->assertNotFound();
});
