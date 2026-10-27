<?php

declare(strict_types=1);

use App\Models\Absence;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\Tenant;
use App\Models\Term;
use Illuminate\Testing\TestResponse;

/**
 * Two families in one group. Student A has a financial guardian (G1) and a second guardian (G2); student B has G3.
 * G1, G2, G3 and student A have portal accounts; G4 (also A's) has none.
 *
 * @return array{tenant: Tenant, owner: string, group: App\Models\Group, a: Student, b: Student, g: array<string, Guardian>, token: array<string, string>}
 */
function portalWorld(): array
{
    [$tenant, $owner] = ownerSession();
    $group = makeGroup($tenant, makeCurrentYear($tenant));
    $a = makeStudent($tenant, 'Amine', 'Alpha');
    $b = makeStudent($tenant, 'Bilal', 'Beta');
    enrollNow($tenant, $a, $group);
    enrollNow($tenant, $b, $group);

    $make = fn (Student $s, string $first, bool $financial): Guardian => inTenant($tenant, function () use ($tenant, $s, $first, $financial): Guardian {
        $guardian = Guardian::query()->create(['first_name' => $first, 'last_name' => 'Family', 'phone' => '0555000000']);
        $s->guardians()->attach($guardian->id, [
            'tenant_id' => $tenant->id, 'relationship' => 'parent', 'is_primary' => $financial, 'is_financial_responsible' => $financial,
        ]);

        return $guardian;
    });

    $g = ['g1' => $make($a, 'Gone', true), 'g2' => $make($a, 'Gtwo', false), 'g3' => $make($b, 'Gthree', true), 'g4' => $make($a, 'Gfour', false)];

    $token = [];
    foreach (['g1', 'g2', 'g3'] as $key) {
        portalAccount($tenant, $owner, 'guardians', $g[$key]->id, "{$key}@family.test")->assertCreated();
        $token[$key] = tokenFor($tenant, "{$key}@family.test");
    }
    portalAccount($tenant, $owner, 'students', $a->id, 'a@student.test')->assertCreated();
    $token['a'] = tokenFor($tenant, 'a@student.test');

    return compact('tenant', 'owner', 'group', 'a', 'b', 'g', 'token');
}

function portalAccount(Tenant $tenant, string $token, string $type, int $id, string $email, string $password = TEST_PASSWORD): TestResponse
{
    return api($tenant, $token)->postJson("/api/v1/portal-accounts/{$type}/{$id}", ['email' => $email, 'password' => $password]);
}

it('creates, shows and manages portal accounts', function (): void {
    [$tenant, $owner] = ownerSession();
    $guardian = inTenant($tenant, fn () => Guardian::query()->create(['first_name' => 'Nadia', 'last_name' => 'Benali', 'phone' => '0555123456']));
    $student = makeStudent($tenant);

    api($tenant, $owner)->getJson("/api/v1/portal-accounts/guardians/{$guardian->id}")->assertNotFound();
    portalAccount($tenant, $owner, 'guardians', $guardian->id, 'bad-email')->assertUnprocessable()->assertJsonValidationErrors('email');
    portalAccount($tenant, $owner, 'guardians', $guardian->id, 'nadia@family.test', 'weak')->assertUnprocessable()->assertJsonValidationErrors('password');
    portalAccount($tenant, $owner, 'pets', $guardian->id, 'x@family.test')->assertNotFound();

    portalAccount($tenant, $owner, 'guardians', $guardian->id, 'Nadia@Family.test')->assertCreated()
        ->assertJsonPath('data.email', 'nadia@family.test')->assertJsonPath('data.is_active', true)->assertJsonPath('data.profile_id', $guardian->id);
    portalAccount($tenant, $owner, 'guardians', $guardian->id, 'again@family.test')->assertUnprocessable();                 // one account per person
    portalAccount($tenant, $owner, 'students', $student->id, 'nadia@family.test')->assertUnprocessable()->assertJsonValidationErrors('email'); // email taken
    api($tenant, $owner)->getJson("/api/v1/portal-accounts/guardians/{$guardian->id}")->assertOk()->assertJsonPath('data.email', 'nadia@family.test');

    $token = tokenFor($tenant, 'nadia@family.test');
    api($tenant, $token)->getJson('/api/v1/portal/students')->assertForbidden(); // linked to no child yet

    // the account carries the "parent" role and no staff permission
    api($tenant, $token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('user.roles.0', 'parent');
    api($tenant, $token)->getJson('/api/v1/students')->assertForbidden();
    api($tenant, $token)->postJson('/api/v1/messages', ['channel' => 'sms', 'body' => 'x', 'audience' => ['type' => 'guardians', 'all' => true]])->assertForbidden();

    api($tenant, $owner)->patchJson("/api/v1/portal-accounts/guardians/{$guardian->id}", [])->assertUnprocessable();
    api($tenant, $owner)->patchJson("/api/v1/portal-accounts/guardians/{$guardian->id}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
    api($tenant, $token)->getJson('/api/v1/auth/me')->assertUnauthorized();                  // signed out everywhere
    loginAs($tenant, 'nadia@family.test')->assertUnauthorized();

    api($tenant, $owner)->patchJson("/api/v1/portal-accounts/guardians/{$guardian->id}", ['is_active' => true, 'password' => 'An0ther-Str0ng-Pass!'])->assertOk();
    loginAs($tenant, 'nadia@family.test')->assertUnauthorized();
    loginAs($tenant, 'nadia@family.test', 'An0ther-Str0ng-Pass!')->assertOk();
});

it('lets only authorised staff manage portal accounts and keeps tenants apart', function (): void {
    [$tenant, $owner] = ownerSession();
    $student = makeStudent($tenant);

    foreach (['teacher', 'accountant'] as $role) {
        $token = roleToken($tenant, $role);
        portalAccount($tenant, $token, 'students', $student->id, "{$role}@x.test")->assertForbidden();
        api($tenant, $token)->getJson("/api/v1/portal-accounts/students/{$student->id}")->assertForbidden();
    }

    portalAccount($tenant, roleToken($tenant, 'secretary'), 'students', $student->id, 'kid@x.test')->assertCreated();

    [$other, $otherOwner] = ownerSession('school-b');
    portalAccount($other, $otherOwner, 'students', $student->id, 'steal@x.test')->assertNotFound();
    api($other, $otherOwner)->getJson("/api/v1/portal-accounts/students/{$student->id}")->assertNotFound();
});

it('shows a guardian only their own children and keeps staff and strangers out', function (): void {
    ['tenant' => $tenant, 'owner' => $owner, 'a' => $a, 'b' => $b, 'token' => $token] = portalWorld();

    $mine = api($tenant, $token['g1'])->getJson('/api/v1/portal/students')->assertOk();
    expect($mine->json('data'))->toHaveCount(1)
        ->and($mine->json('data.0.id'))->toBe($a->id)
        ->and($mine->json('data.0.current_group.name'))->toBe('G1')
        ->and($mine->json('data.0.can_view_finance'))->toBeTrue()
        ->and($mine->json('data.0'))->not->toHaveKey('medical_notes')->not->toHaveKey('national_id');

    api($tenant, $token['g2'])->getJson('/api/v1/portal/students')->assertJsonPath('data.0.can_view_finance', false);
    api($tenant, $token['g3'])->getJson('/api/v1/portal/students')->assertJsonPath('data.0.id', $b->id);

    api($tenant, $token['g1'])->getJson("/api/v1/portal/students/{$a->id}")->assertOk()->assertJsonPath('data.first_name', 'Amine');
    foreach (['', '/absences', '/attendance-summary?from=2026-01-01&to=2026-12-31', '/report-card', '/account', '/invoices', '/payments'] as $path) {
        api($tenant, $token['g1'])->getJson("/api/v1/portal/students/{$b->id}{$path}")->assertNotFound();       // another family's child
    }
    api($tenant, $token['g1'])->getJson('/api/v1/portal/students/abc')->assertNotFound();

    // staff are not portal users, and portal users are not staff
    api($tenant, $owner)->getJson('/api/v1/portal/students')->assertForbidden();
    api($tenant, roleToken($tenant, 'teacher'))->getJson("/api/v1/portal/students/{$a->id}")->assertForbidden();
    api($tenant)->getJson('/api/v1/portal/students')->assertUnauthorized();

    [$other, $otherOwner] = ownerSession('school-b');
    expect(api($other, $token['g1'])->getJson('/api/v1/portal/students')->status())->toBeIn([401, 403]); // token of another tenant
});

it('shows attendance, the timetable and the report card of the child', function (): void {
    ['tenant' => $tenant, 'owner' => $owner, 'group' => $group, 'a' => $a, 'b' => $b, 'token' => $token] = portalWorld();
    $teacher = makeTeacher($tenant);
    $lessonOf = fn (int $daysAgo) => makeLesson($tenant, $group, $teacher, now()->subDays($daysAgo)->toDateString(), overrides: ['status' => 'completed']);

    foreach ([1 => 'absent', 2 => 'late'] as $daysAgo => $type) {
        $lesson = $lessonOf($daysAgo);
        inTenant($tenant, fn () => Absence::query()->create(['lesson_id' => $lesson->id, 'student_id' => $a->id, 'type' => $type, 'is_justified' => false]));
    }
    $lessonB = $lessonOf(3);
    inTenant($tenant, fn () => Absence::query()->create(['lesson_id' => $lessonB->id, 'student_id' => $b->id, 'type' => 'absent', 'is_justified' => false]));
    makeLesson($tenant, $group, $teacher, now()->addDays(2)->toDateString(), '10:00', '11:30');

    $absences = api($tenant, $token['g2'])->getJson("/api/v1/portal/students/{$a->id}/absences")->assertOk();
    expect($absences->json('data'))->toHaveCount(2)->and($absences->json('data.0.subject'))->not->toBeNull();
    api($tenant, $token['a'])->getJson("/api/v1/portal/students/{$a->id}/absences?type=late")->assertOk()->assertJsonCount(1, 'data');

    api($tenant, $token['g1'])->getJson("/api/v1/portal/students/{$a->id}/attendance-summary?from=".now()->subDays(10)->toDateString().'&to='.now()->toDateString())
        ->assertOk()->assertJsonPath('absent', 1)->assertJsonPath('late', 1)->assertJsonPath('lessons_count', 3);

    $lessons = api($tenant, $token['g1'])->getJson("/api/v1/portal/students/{$a->id}/lessons?from=".now()->toDateString().'&to='.now()->addDays(7)->toDateString())->assertOk();
    expect($lessons->json('data'))->toHaveCount(1)->and($lessons->json('data.0.starts_at'))->toBe('10:00')
        ->and($lessons->json('data.0.teacher.first_name'))->toBe('Karim');
    api($tenant, $token['g1'])->getJson("/api/v1/portal/students/{$a->id}/lessons?from=2026-01-01&to=2026-06-01")->assertUnprocessable();

    // exam + grades: the report card shows the child's own figures and the class summary only
    $term = inTenant($tenant, fn () => Term::query()->create(['academic_year_id' => $group->academic_year_id, 'number' => 1, 'name' => 'Term 1',
        'starts_on' => now()->subMonths(2)->toDateString(), 'ends_on' => now()->addMonths(3)->toDateString()]));
    $exam = api($tenant, $owner)->postJson('/api/v1/exams', ['group_id' => $group->id, 'subject_id' => subjectId($tenant), 'term_id' => $term->id,
        'name' => 'Exam', 'date' => now()->toDateString()])->assertCreated()->json('data.id');
    api($tenant, $owner)->putJson("/api/v1/exams/{$exam}/grades", ['grades' => [
        ['student_id' => $a->id, 'score' => 16, 'status' => 'present'], ['student_id' => $b->id, 'score' => 12, 'status' => 'present'],
    ]])->assertOk();

    $card = api($tenant, $token['a'])->getJson("/api/v1/portal/students/{$a->id}/report-card?term_id={$term->id}")->assertOk();
    expect($card->json('average'))->toBe(16)->and($card->json('rank'))->toBe(1)->and($card->json('class_size'))->toBe(2)
        ->and(json_encode($card->json()))->not->toContain('Bilal');
});

it('shows money only to the financially responsible guardian, without staff details', function (): void {
    ['tenant' => $tenant, 'owner' => $owner, 'a' => $a, 'b' => $b, 'token' => $token] = portalWorld();

    $invoice = fn (Student $s, int $amount) => api($tenant, $owner)->postJson('/api/v1/invoices', [
        'student_id' => $s->id, 'description' => 'Tuition', 'unit_price' => $amount, 'due_date' => now()->addDays(10)->toDateString(),
    ])->assertCreated();
    $invoice($a, 300000);
    $invoice($b, 999900);
    api($tenant, $owner)->postJson('/api/v1/payments', ['student_id' => $a->id, 'amount' => 100000, 'method' => 'cash', 'note' => 'internal remark'])->assertCreated();

    api($tenant, $token['g1'])->getJson("/api/v1/portal/students/{$a->id}/account")->assertOk()
        ->assertJsonPath('billed', 300000)->assertJsonPath('outstanding', 200000)->assertJsonPath('received', 100000);

    $invoices = api($tenant, $token['g1'])->getJson("/api/v1/portal/students/{$a->id}/invoices")->assertOk();
    expect($invoices->json('data'))->toHaveCount(1)->and($invoices->json('data.0.total_amount'))->toBe(300000);

    $payments = api($tenant, $token['g1'])->getJson("/api/v1/portal/students/{$a->id}/payments")->assertOk();
    expect($payments->json('data'))->toHaveCount(1)->and($payments->json('data.0.amount'))->toBe(100000)
        ->and($payments->json('data.0'))->not->toHaveKey('note')->not->toHaveKey('received_by')->not->toHaveKey('guardian_id');

    foreach (['account', 'invoices', 'payments'] as $path) {
        api($tenant, $token['g2'])->getJson("/api/v1/portal/students/{$a->id}/{$path}")->assertForbidden(); // not the payer
        api($tenant, $token['a'])->getJson("/api/v1/portal/students/{$a->id}/{$path}")->assertForbidden();  // the student himself
    }
    api($tenant, $token['g3'])->getJson("/api/v1/portal/students/{$a->id}/invoices")->assertNotFound();
});

it('delivers in-app messages to the inbox of guardians and students with an account', function (): void {
    ['tenant' => $tenant, 'owner' => $owner, 'group' => $group, 'g' => $g, 'token' => $token] = portalWorld();

    // G1, G2, G3 have accounts, G4 has not
    sendPortalMessage($tenant, $owner, ['channel' => 'in_app', 'audience' => ['type' => 'guardians', 'group_id' => $group->id], 'subject' => 'Meeting',
        'body' => 'Dear {guardian_name}, parents meeting for {student_name}'])->assertCreated()->assertJsonPath('queued', 3)->assertJsonPath('skipped_no_contact', 1);

    api($tenant, $token['g1'])->getJson('/api/v1/inbox/unread-count')->assertJsonPath('unread', 1);
    $inbox = api($tenant, $token['g1'])->getJson('/api/v1/inbox')->assertOk();
    expect($inbox->json('data.0.body'))->toBe('Dear Gone Family, parents meeting for Amine Alpha');

    $id = (int) $inbox->json('data.0.id');
    api($tenant, $token['g2'])->postJson("/api/v1/inbox/{$id}/read")->assertNotFound();                      // never a relative's message
    api($tenant, $token['g1'])->postJson("/api/v1/inbox/{$id}/read")->assertOk()->assertJsonPath('data.is_read', true);
    api($tenant, $token['g1'])->getJson('/api/v1/inbox/unread-count')->assertJsonPath('unread', 0);

    // students with an account are reached too; "everyone" in a staff announcement never includes families
    sendPortalMessage($tenant, $owner, ['channel' => 'in_app', 'audience' => ['type' => 'students', 'group_id' => $group->id], 'body' => 'Hello {student_name}'])
        ->assertCreated()->assertJsonPath('queued', 1)->assertJsonPath('skipped_no_contact', 1);
    api($tenant, $token['a'])->getJson('/api/v1/inbox/unread-count')->assertJsonPath('unread', 1);

    sendPortalMessage($tenant, $owner, ['channel' => 'in_app', 'audience' => ['type' => 'users', 'all' => true], 'body' => 'Staff only'])
        ->assertCreated()->assertJsonPath('queued', 1);
    api($tenant, $token['g3'])->getJson('/api/v1/inbox/unread-count')->assertJsonPath('unread', 1);          // only the guardian message from before

    // a deactivated account is no longer addressed
    api($tenant, $owner)->patchJson("/api/v1/portal-accounts/guardians/{$g['g2']->id}", ['is_active' => false])->assertOk();
    sendPortalMessage($tenant, $owner, ['channel' => 'in_app', 'audience' => ['type' => 'guardians', 'group_id' => $group->id], 'body' => 'Again'])
        ->assertCreated()->assertJsonPath('queued', 2)->assertJsonPath('skipped_no_contact', 2);
});

function sendPortalMessage(Tenant $tenant, string $token, array $body): TestResponse
{
    return api($tenant, $token)->postJson('/api/v1/messages', $body);
}
