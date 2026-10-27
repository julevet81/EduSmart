<?php

declare(strict_types=1);

use App\Models\AcademicYear;
use App\Models\Group;
use App\Models\Level;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\Tenant;
use App\Models\Term;
use Illuminate\Testing\TestResponse;

/**
 * Tenant with a current year (two terms), a group and two enrolled students.
 *
 * @return array{0: Tenant, 1: string, 2: Group, 3: list<Student>, 4: array{0: Term, 1: Term}, 5: AcademicYear}
 */
function examWorld(int $students = 2): array
{
    [$tenant, $token] = ownerSession();
    $year = makeCurrentYear($tenant);
    $group = makeGroup($tenant, $year);

    $terms = inTenant($tenant, fn (): array => [
        Term::query()->create(['academic_year_id' => $year->id, 'number' => 1, 'name' => 'Term 1',
            'starts_on' => now()->subMonths(2)->toDateString(), 'ends_on' => now()->addMonths(3)->toDateString()]),
        Term::query()->create(['academic_year_id' => $year->id, 'number' => 2, 'name' => 'Term 2',
            'starts_on' => now()->addMonths(3)->addDay()->toDateString(), 'ends_on' => now()->addMonths(8)->toDateString()]),
    ]);

    $list = [];
    foreach (range(1, $students) as $i) {
        $student = makeStudent($tenant, 'Student'.$i, 'Name'.$i);
        enrollNow($tenant, $student, $group);
        $list[] = $student;
    }

    return [$tenant, $token, $group, $list, $terms, $year];
}

function makeExam(Tenant $tenant, string $token, Group $group, Term $term, array $overrides = []): TestResponse
{
    return api($tenant, $token)->postJson('/api/v1/exams', array_merge([
        'group_id' => $group->id, 'subject_id' => subjectId($tenant), 'term_id' => $term->id,
        'name' => 'Exam', 'date' => now()->toDateString(),
    ], $overrides));
}

/** @param  list<array{0: Student, 1: float|int|null, 2?: string}>  $rows  [student, score, status] */
function putGrades(Tenant $tenant, string $token, int $examId, array $rows): TestResponse
{
    return api($tenant, $token)->putJson("/api/v1/exams/{$examId}/grades", ['grades' => array_map(fn (array $r): array => [
        'student_id' => $r[0]->id, 'score' => $r[1], 'status' => $r[2] ?? 'present',
    ], $rows)]);
}

function setCoefficient(Tenant $tenant, string $token, string $subjectCode, int $coefficient): void
{
    api($tenant, $token)->postJson('/api/v1/subject-coefficients', [
        'level_id' => inTenant($tenant, fn () => Level::query()->where('code', '1AM')->value('id')),
        'subject_id' => subjectId($tenant, $subjectCode), 'coefficient' => $coefficient,
    ])->assertCreated();
}

it('creates exams with defaults and validates the term and the date', function (): void {
    [$tenant, $token, $group, , [$term1]] = examWorld();
    [$other] = ownerSession('school-b');
    $foreignTerm = inTenant($other, fn () => Term::query()->create([
        'academic_year_id' => makeCurrentYear($other)->id, 'number' => 1, 'name' => 'T',
        'starts_on' => now()->subMonth()->toDateString(), 'ends_on' => now()->addMonth()->toDateString(),
    ]));

    makeExam($tenant, $token, $group, $term1)->assertCreated()
        ->assertJsonPath('data.max_score', 20)->assertJsonPath('data.coefficient', 1)->assertJsonPath('data.kind', 'exam')
        ->assertJsonPath('data.group.name', 'G1');

    makeExam($tenant, $token, $group, $term1, ['date' => now()->subYear()->toDateString()])->assertUnprocessable();
    makeExam($tenant, $token, $group, $foreignTerm)->assertUnprocessable()->assertJsonValidationErrors('term_id');
    makeExam($tenant, $token, $group, $term1, ['max_score' => 0])->assertUnprocessable()->assertJsonValidationErrors('max_score');
    makeExam($tenant, $token, $group, $term1, ['kind' => 'quiz'])->assertUnprocessable()->assertJsonValidationErrors('kind');

    api($tenant, $token)->getJson("/api/v1/exams?group_id={$group->id}&term_id={$term1->id}")->assertOk()->assertJsonCount(1, 'data');
});

it('enters grades with decimals, absences and clears, and rejects invalid ones', function (): void {
    [$tenant, $token, $group, [$amine, $bilal], [$term1]] = examWorld();
    $outsider = makeStudent($tenant, 'Out', 'Sider');
    $examId = (int) makeExam($tenant, $token, $group, $term1, ['max_score' => 20])->json('data.id');

    $roster = api($tenant, $token)->getJson("/api/v1/exams/{$examId}/grades")->assertOk();
    expect($roster->json('data'))->toHaveCount(2)->and($roster->json('data.0.status'))->toBeNull();

    putGrades($tenant, $token, $examId, [[$amine, 14.75], [$bilal, null, 'absent']])->assertOk()
        ->assertJsonPath('data.0.score', 14.75)->assertJsonPath('data.1.status', 'absent')->assertJsonPath('data.1.score', null);

    putGrades($tenant, $token, $examId, [[$amine, 20.5]])->assertUnprocessable();
    putGrades($tenant, $token, $examId, [[$amine, 12.345]])->assertUnprocessable();
    putGrades($tenant, $token, $examId, [[$amine, -1]])->assertUnprocessable();
    putGrades($tenant, $token, $examId, [[$amine, null]])->assertUnprocessable();
    putGrades($tenant, $token, $examId, [[$outsider, 10]])->assertUnprocessable();

    // untouched by the failed attempts, then replaced, then cleared
    putGrades($tenant, $token, $examId, [[$amine, 16]])->assertOk()->assertJsonPath('data.0.score', 16);
    putGrades($tenant, $token, $examId, [[$amine, null, 'clear']])->assertOk()->assertJsonPath('data.0.status', null);
});

it('locks an exam against changes until it is unlocked', function (): void {
    [$tenant, $token, $group, [$amine], [$term1]] = examWorld();
    $examId = (int) makeExam($tenant, $token, $group, $term1)->json('data.id');
    putGrades($tenant, $token, $examId, [[$amine, 10]])->assertOk();

    api($tenant, $token)->postJson("/api/v1/exams/{$examId}/lock")->assertOk()->assertJsonPath('data.is_locked', true);
    putGrades($tenant, $token, $examId, [[$amine, 11]])->assertUnprocessable();
    api($tenant, $token)->patchJson("/api/v1/exams/{$examId}", ['name' => 'Renamed'])->assertUnprocessable();
    api($tenant, $token)->deleteJson("/api/v1/exams/{$examId}")->assertUnprocessable();

    api($tenant, $token)->postJson("/api/v1/exams/{$examId}/unlock")->assertOk();
    putGrades($tenant, $token, $examId, [[$amine, 11]])->assertOk();
});

it('protects exams that have grades from deletion and from a new maximum score', function (): void {
    [$tenant, $token, $group, [$amine], [$term1]] = examWorld();
    $empty = (int) makeExam($tenant, $token, $group, $term1)->json('data.id');
    $graded = (int) makeExam($tenant, $token, $group, $term1, ['name' => 'Graded'])->json('data.id');
    putGrades($tenant, $token, $graded, [[$amine, 10]])->assertOk();

    api($tenant, $token)->patchJson("/api/v1/exams/{$graded}", ['max_score' => 10])->assertUnprocessable();
    api($tenant, $token)->patchJson("/api/v1/exams/{$graded}", ['coefficient' => 3])->assertOk()->assertJsonPath('data.coefficient', 3);
    api($tenant, $token)->deleteJson("/api/v1/exams/{$graded}")->assertStatus(409);
    api($tenant, $token)->patchJson("/api/v1/exams/{$empty}", ['max_score' => 10])->assertOk();
    api($tenant, $token)->deleteJson("/api/v1/exams/{$empty}")->assertNoContent();
});

it('computes weighted subject and general averages, ranks and class statistics', function (): void {
    [$tenant, $token, $group, [$amine, $bilal], [$term1]] = examWorld();
    setCoefficient($tenant, $token, 'MATH', 5);
    setCoefficient($tenant, $token, 'AR', 3);

    $math1 = (int) makeExam($tenant, $token, $group, $term1, ['name' => 'Math 1'])->json('data.id');
    $math2 = (int) makeExam($tenant, $token, $group, $term1, ['name' => 'Math 2', 'max_score' => 10, 'coefficient' => 2])->json('data.id');
    $arabic = (int) makeExam($tenant, $token, $group, $term1, ['name' => 'Arabic', 'subject_id' => subjectId($tenant, 'AR')])->json('data.id');

    putGrades($tenant, $token, $math1, [[$amine, 16], [$bilal, 10]])->assertOk();
    putGrades($tenant, $token, $math2, [[$amine, 9], [$bilal, 4]])->assertOk(); // 18/20 and 8/20
    putGrades($tenant, $token, $arabic, [[$amine, 12], [$bilal, 14]])->assertOk();

    $result = api($tenant, $token)->getJson("/api/v1/groups/{$group->id}/results?term_id={$term1->id}")->assertOk();

    // Amine: math (16*1+18*2)/3 = 17.33, arabic 12 => (17.33*5 + 12*3)/8 = 15.33
    expect($result->json('students.0.student.first_name'))->toBe('Student1')
        ->and($result->json('students.0.average'))->toBe(15.33)
        ->and($result->json('students.0.rank'))->toBe(1)
        ->and($result->json('students.0.appreciation'))->toBe('good')
        ->and($result->json('students.0.subjects'))->toHaveCount(2)
        ->and($result->json('students.1.average'))->toBe(10.67)
        ->and($result->json('students.1.rank'))->toBe(2)
        ->and($result->json('students.1.appreciation'))->toBe('passable')
        ->and($result->json('stats.average'))->toBe(13)
        ->and($result->json('stats.min'))->toBe(10.67)
        ->and($result->json('stats.max'))->toBe(15.33);

    $math = collect($result->json('subjects'))->firstWhere('code', 'MATH');
    expect($math['coefficient'])->toBe(5)->and($math['class_max'])->toBe(17.33)->and($math['class_min'])->toBe(8.67);
});

it('gives equal averages the same rank, weighs unconfigured subjects 1 and leaves absentees out', function (): void {
    [$tenant, $token, $group, [$a, $b, $c], [$term1]] = examWorld(3);
    $exam = (int) makeExam($tenant, $token, $group, $term1)->json('data.id');

    putGrades($tenant, $token, $exam, [[$a, 15], [$b, 15], [$c, null, 'absent']])->assertOk();

    $result = api($tenant, $token)->getJson("/api/v1/groups/{$group->id}/results?term_id={$term1->id}")->assertOk();

    expect($result->json('students.0.rank'))->toBe(1)->and($result->json('students.1.rank'))->toBe(1)
        ->and($result->json('students.2.rank'))->toBeNull()->and($result->json('students.2.average'))->toBeNull()
        ->and($result->json('stats.graded'))->toBe(2)->and($result->json('stats.size'))->toBe(3);

    // a third student scoring lower is ranked 3rd, not 2nd
    putGrades($tenant, $token, $exam, [[$c, 9]])->assertOk();
    $again = api($tenant, $token)->getJson("/api/v1/groups/{$group->id}/results?term_id={$term1->id}")->json('students');
    expect(collect($again)->pluck('rank')->all())->toBe([1, 1, 3]);
});

it('averages the terms for the whole year', function (): void {
    [$tenant, $token, $group, [$amine], [$term1, $term2]] = examWorld(1);

    $first = (int) makeExam($tenant, $token, $group, $term1)->json('data.id');
    $second = (int) makeExam($tenant, $token, $group, $term2, ['date' => now()->addMonths(4)->toDateString()])->json('data.id');
    putGrades($tenant, $token, $first, [[$amine, 10]])->assertOk();
    // the second term's exam lies in the future: its roster still holds the student (enrolled since before)
    putGrades($tenant, $token, $second, [[$amine, 18]])->assertOk();

    api($tenant, $token)->getJson("/api/v1/groups/{$group->id}/results?term_id={$term2->id}")->assertJsonPath('students.0.average', 18);
    api($tenant, $token)->getJson("/api/v1/groups/{$group->id}/results")->assertOk()->assertJsonPath('students.0.average', 14);
});

it('builds a report card with exams, rank, class figures and the council remark', function (): void {
    [$tenant, $token, $group, [$amine, $bilal], [$term1]] = examWorld();
    $exam = (int) makeExam($tenant, $token, $group, $term1, ['name' => 'First test', 'kind' => 'test'])->json('data.id');
    putGrades($tenant, $token, $exam, [[$amine, 17], [$bilal, 9]])->assertOk();

    api($tenant, $token)->putJson("/api/v1/students/{$bilal->id}/term-remarks/{$term1->id}", ['remark' => 'Can do better'])->assertOk();
    api($tenant, $token)->putJson("/api/v1/students/{$bilal->id}/term-remarks/{$term1->id}", ['remark' => 'Progressing'])->assertOk();

    $card = api($tenant, $token)->getJson("/api/v1/students/{$bilal->id}/report-card?term_id={$term1->id}")->assertOk();

    expect($card->json('average'))->toBe(9)
        ->and($card->json('rank'))->toBe(2)
        ->and($card->json('class_size'))->toBe(2)
        ->and($card->json('appreciation'))->toBe('weak')
        ->and($card->json('remark'))->toBe('Progressing')
        ->and($card->json('class.average'))->toBe(13)
        ->and($card->json('subjects.0.student_average'))->toBe(9)
        ->and($card->json('subjects.0.exams.0.name'))->toBe('First test')
        ->and($card->json('subjects.0.exams.0.score'))->toBe(9)
        ->and($card->json('group.level'))->not->toBeNull();

    $nobody = makeStudent($tenant, 'No', 'Enrollment');
    api($tenant, $token)->getJson("/api/v1/students/{$nobody->id}/report-card")->assertUnprocessable();
});

it('manages subject coefficients without duplicates per level and track', function (): void {
    [$tenant, $token] = ownerSession();
    [$other, $otherToken] = ownerSession('school-b');
    $levelId = (int) inTenant($tenant, fn () => Level::query()->where('code', '1AM')->value('id'));
    $foreignLevel = (int) inTenant($other, fn () => Level::query()->where('code', '1AM')->value('id'));

    setCoefficient($tenant, $token, 'MATH', 5);
    api($tenant, $token)->postJson('/api/v1/subject-coefficients', ['level_id' => $levelId, 'subject_id' => subjectId($tenant), 'coefficient' => 4])
        ->assertUnprocessable()->assertJsonValidationErrors('subject_id');
    api($tenant, $token)->postJson('/api/v1/subject-coefficients', ['level_id' => $foreignLevel, 'subject_id' => subjectId($tenant, 'AR'), 'coefficient' => 4])
        ->assertUnprocessable()->assertJsonValidationErrors('level_id');
    api($tenant, $token)->postJson('/api/v1/subject-coefficients', ['level_id' => $levelId, 'subject_id' => subjectId($tenant, 'AR'), 'coefficient' => 11])
        ->assertUnprocessable()->assertJsonValidationErrors('coefficient');

    // the same subject may carry another weight for a specific track
    $trackId = (int) inTenant($tenant, fn () => App\Models\Track::query()->where('code', 'M')->value('id'));
    api($tenant, $token)->postJson('/api/v1/subject-coefficients', ['level_id' => $levelId, 'track_id' => $trackId, 'subject_id' => subjectId($tenant), 'coefficient' => 7])
        ->assertCreated();

    api($tenant, $token)->getJson("/api/v1/subject-coefficients?level_id={$levelId}")->assertOk()->assertJsonCount(2, 'data');
    api($other, $otherToken)->getJson('/api/v1/subject-coefficients')->assertOk()->assertJsonCount(0, 'data');
});

it('lets a teacher manage only the exams of the groups and subjects they teach', function (): void {
    [$tenant, $owner, $group, [$amine], [$term1]] = examWorld();
    [$token, $teacher] = teacherSession($tenant);
    $colleague = makeTeacher($tenant, null, 'Other');
    makeLesson($tenant, $group, $teacher, now()->toDateString());

    $mine = (int) makeExam($tenant, $token, $group, $term1, ['teacher_id' => $colleague->id])->assertCreated()
        ->assertJsonPath('data.teacher_id', $teacher->id)->json('data.id'); // teacher_id is forced to the caller

    // a subject they do not teach in that group
    makeExam($tenant, $token, $group, $term1, ['subject_id' => subjectId($tenant, 'AR')])->assertForbidden();

    $theirs = (int) makeExam($tenant, $owner, $group, $term1, ['name' => 'Colleague', 'teacher_id' => $colleague->id])->json('data.id');

    api($tenant, $token)->getJson('/api/v1/exams')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine);
    api($tenant, $token)->getJson("/api/v1/exams/{$theirs}")->assertNotFound();
    api($tenant, $token)->getJson("/api/v1/exams/{$theirs}/grades")->assertNotFound();
    putGrades($tenant, $token, $theirs, [[$amine, 10]])->assertNotFound();
    api($tenant, $token)->patchJson("/api/v1/exams/{$theirs}", ['name' => 'Hijack'])->assertNotFound();

    putGrades($tenant, $token, $mine, [[$amine, 13]])->assertOk();
    api($tenant, $token)->patchJson("/api/v1/exams/{$mine}", ['name' => 'Renamed'])->assertOk();

    // no lock, no results, no coefficients for a teacher
    api($tenant, $token)->postJson("/api/v1/exams/{$mine}/lock")->assertForbidden();
    api($tenant, $token)->getJson("/api/v1/groups/{$group->id}/results")->assertForbidden();
    api($tenant, $token)->getJson("/api/v1/students/{$amine->id}/report-card")->assertForbidden();
    api($tenant, $token)->getJson('/api/v1/subject-coefficients')->assertForbidden();
});

it('enforces exam permissions per role and keeps tenants apart', function (): void {
    [$tenant, $owner, $group, [$amine], [$term1]] = examWorld();
    $examId = (int) makeExam($tenant, $owner, $group, $term1)->json('data.id');

    $secretary = roleToken($tenant, 'secretary');
    api($tenant, $secretary)->getJson('/api/v1/exams')->assertOk();
    api($tenant, $secretary)->getJson("/api/v1/groups/{$group->id}/results")->assertOk();
    putGrades($tenant, $secretary, $examId, [[$amine, 12]])->assertOk();
    makeExam($tenant, $secretary, $group, $term1)->assertForbidden();
    api($tenant, $secretary)->postJson("/api/v1/exams/{$examId}/lock")->assertForbidden();

    $accountant = roleToken($tenant, 'accountant');
    api($tenant, $accountant)->getJson('/api/v1/exams')->assertForbidden();
    api($tenant, $accountant)->getJson("/api/v1/groups/{$group->id}/results")->assertForbidden();

    $manager = roleToken($tenant, 'manager');
    api($tenant, $manager)->postJson("/api/v1/exams/{$examId}/lock")->assertOk();

    [$other, $otherToken] = ownerSession('school-b');
    api($other, $otherToken)->getJson("/api/v1/exams/{$examId}")->assertNotFound();
    api($other, $otherToken)->getJson("/api/v1/exams/{$examId}/grades")->assertNotFound();
    api($other, $otherToken)->getJson("/api/v1/groups/{$group->id}/results")->assertNotFound();
    api($other, $otherToken)->getJson("/api/v1/students/{$amine->id}/report-card")->assertNotFound();
    api($other, $otherToken)->getJson('/api/v1/exams')->assertOk()->assertJsonCount(0, 'data');
});
