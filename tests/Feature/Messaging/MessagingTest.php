<?php

declare(strict_types=1);

use App\Actions\Messaging\DispatchMessages;
use App\Models\Absence;
use App\Models\Guardian;
use App\Models\Message;
use App\Models\Student;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Messaging\ChannelDriver;
use Illuminate\Testing\TestResponse;

/** Test double: a gateway that is always down. */
final class FailingMessageDriver implements ChannelDriver
{
    public function send(Message $message): void
    {
        throw new RuntimeException('Gateway down');
    }
}

/**
 * Group G1 with two enrolled students. Student1's guardian has a phone, Student2's guardian has none.
 *
 * @return array{0: Tenant, 1: string, 2: App\Models\Group, 3: list<Student>, 4: list<Guardian>}
 */
function messagingWorld(): array
{
    [$tenant, $token] = ownerSession();
    $group = makeGroup($tenant, makeCurrentYear($tenant));
    $students = [];
    $guardians = [];

    foreach ([1, 2] as $i) {
        $student = makeStudent($tenant, 'Student'.$i, 'Name'.$i);
        enrollNow($tenant, $student, $group);
        $students[] = $student;
        $guardians[] = linkGuardian($tenant, $student, $i === 1 ? '0555123456' : null, 'Nadia'.$i, true);
    }

    return [$tenant, $token, $group, $students, $guardians];
}

function linkGuardian(Tenant $tenant, Student $student, ?string $phone, string $first = 'Parent', bool $financial = false): Guardian
{
    return inTenant($tenant, function () use ($tenant, $student, $phone, $first, $financial): Guardian {
        $guardian = Guardian::query()->create(['first_name' => $first, 'last_name' => 'Benali', 'phone' => $phone]);
        $student->guardians()->attach($guardian->id, [
            'tenant_id' => $tenant->id, 'relationship' => 'father', 'is_primary' => true, 'is_financial_responsible' => $financial,
        ]);

        return $guardian;
    });
}

function sendMessage(Tenant $tenant, string $token, array $body): TestResponse
{
    return api($tenant, $token)->postJson('/api/v1/messages', $body);
}

/** @param  list<int>  $daysAgo */
function unjustifiedAbsences(Tenant $tenant, App\Models\Group $group, Student $student, array $daysAgo, bool $justified = false): void
{
    $teacher = makeTeacher($tenant, null, 'T'.fake()->unique()->numerify('###'));

    foreach ($daysAgo as $days) {
        $lesson = makeLesson($tenant, $group, $teacher, now()->subDays($days)->toDateString());
        inTenant($tenant, fn () => Absence::query()->create([
            'lesson_id' => $lesson->id, 'student_id' => $student->id, 'type' => 'absent', 'is_justified' => $justified,
        ]));
    }
}

it('manages templates and rejects unknown placeholders', function (): void {
    [$tenant, $token] = ownerSession();
    $body = ['key' => 'greeting', 'name' => 'Greeting', 'body' => 'Hello {guardian_name}, about {student_name}.'];

    api($tenant, $token)->postJson('/api/v1/message-templates', $body)->assertCreated()->assertJsonPath('data.key', 'greeting');
    api($tenant, $token)->postJson('/api/v1/message-templates', $body)->assertUnprocessable()->assertJsonValidationErrors('key');
    api($tenant, $token)->postJson('/api/v1/message-templates', ['key' => 'Bad Key'] + $body)->assertUnprocessable()->assertJsonValidationErrors('key');
    api($tenant, $token)->postJson('/api/v1/message-templates', ['key' => 'other', 'body' => 'Hi {password}'] + $body)->assertUnprocessable();

    $id = api($tenant, $token)->getJson('/api/v1/message-templates')->assertOk()->assertJsonCount(1, 'data')->json('data.0.id');
    api($tenant, $token)->patchJson("/api/v1/message-templates/{$id}", ['body' => 'Hi {secret}'])->assertUnprocessable();
    api($tenant, $token)->patchJson("/api/v1/message-templates/{$id}", ['body' => 'Hi {student_name}'])->assertOk()->assertJsonPath('data.body', 'Hi {student_name}');
    api($tenant, $token)->deleteJson("/api/v1/message-templates/{$id}")->assertNoContent();
});

it('sends a personalised message to the guardians of a group and reports who has no contact', function (): void {
    [$tenant, $token, $group] = messagingWorld();

    $result = sendMessage($tenant, $token, [
        'channel' => 'sms', 'audience' => ['type' => 'guardians', 'group_id' => $group->id],
        'body' => 'Dear {guardian_name}, {student_name} ({group_name}) - {institution}',
    ])->assertCreated()->assertJsonPath('queued', 1)->assertJsonPath('skipped_no_contact', 1);

    $messages = api($tenant, $token)->getJson('/api/v1/messages?batch_id='.$result->json('batch_id'))->assertOk();

    expect($messages->json('data'))->toHaveCount(1)
        ->and($messages->json('data.0.body'))->toBe('Dear Nadia1 Benali, Student1 Name1 (G1) - School-a')
        ->and($messages->json('data.0.to'))->toBe('0555123456')
        ->and($messages->json('data.0.status'))->toBe('queued')
        ->and($messages->json('data.0.recipient.type'))->toBe('guardian');

    // nobody can be reached by email: nothing is created
    sendMessage($tenant, $token, ['channel' => 'email', 'audience' => ['type' => 'guardians', 'group_id' => $group->id], 'body' => 'Hi'])
        ->assertUnprocessable();
});

it('validates what can be sent and to whom', function (): void {
    [$tenant, $token, $group, [$s1]] = messagingWorld();
    [$other, $otherToken] = ownerSession('school-b');
    $foreignGroup = makeGroup($other, makeCurrentYear($other));

    $audience = ['type' => 'guardians', 'group_id' => $group->id];

    sendMessage($tenant, $token, ['channel' => 'in_app', 'audience' => $audience, 'body' => 'Hi'])->assertUnprocessable(); // guardians have no inbox
    sendMessage($tenant, $token, ['channel' => 'pigeon', 'audience' => $audience, 'body' => 'Hi'])->assertUnprocessable()->assertJsonValidationErrors('channel');
    sendMessage($tenant, $token, ['channel' => 'sms', 'audience' => $audience])->assertUnprocessable()->assertJsonValidationErrors('body');
    sendMessage($tenant, $token, ['channel' => 'sms', 'audience' => $audience, 'body' => 'Hi {secret}'])->assertUnprocessable();
    sendMessage($tenant, $token, ['channel' => 'sms', 'audience' => $audience, 'body' => 'Hi', 'template_key' => 'missing'])->assertUnprocessable();
    sendMessage($tenant, $token, ['channel' => 'sms', 'audience' => ['type' => 'guardians', 'group_id' => $foreignGroup->id], 'body' => 'Hi'])
        ->assertUnprocessable()->assertJsonValidationErrors('audience.group_id');
    sendMessage($tenant, $token, ['channel' => 'sms', 'audience' => ['type' => 'guardians'], 'body' => 'Hi'])->assertUnprocessable(); // empty audience

    api($tenant, $token)->postJson('/api/v1/message-templates', ['key' => 'mail_only', 'name' => 'Mail', 'channel' => 'email', 'body' => 'Hi'])->assertCreated();
    sendMessage($tenant, $token, ['channel' => 'sms', 'audience' => $audience, 'template_key' => 'mail_only'])->assertUnprocessable();

    api($tenant, $token)->postJson('/api/v1/message-templates', ['key' => 'fees', 'name' => 'Fees', 'subject' => 'Fees', 'body' => 'Hello {guardian_name}', 'is_active' => false])->assertCreated();
    sendMessage($tenant, $token, ['channel' => 'sms', 'audience' => $audience, 'template_key' => 'fees'])->assertUnprocessable(); // inactive

    $id = api($tenant, $token)->getJson('/api/v1/message-templates')->json('data.1.id');
    api($tenant, $token)->patchJson("/api/v1/message-templates/{$id}", ['is_active' => true])->assertOk();
    sendMessage($tenant, $token, ['channel' => 'sms', 'audience' => ['type' => 'guardians', 'student_ids' => [$s1->id]], 'template_key' => 'fees'])
        ->assertCreated()->assertJsonPath('queued', 1);

    api($tenant, $token)->getJson('/api/v1/messages')->assertJsonPath('data.0.subject', 'Fees')->assertJsonPath('data.0.body', 'Hello Nadia1 Benali');
    api($other, $otherToken)->getJson('/api/v1/messages')->assertOk()->assertJsonCount(0, 'data');
});

it('delivers in-app notifications to the inbox of each user only', function (): void {
    [$tenant, $owner] = ownerSession();
    $secretary = roleToken($tenant, 'secretary');
    $secretaryId = (int) inTenant($tenant, fn () => User::query()->where('email', "secretary@{$tenant->slug}.test")->value('id'));

    sendMessage($tenant, $owner, ['channel' => 'in_app', 'kind' => 'announcement', 'subject' => 'Meeting', 'body' => 'Staff meeting at 15:00',
        'audience' => ['type' => 'users', 'all' => true]])->assertCreated()->assertJsonPath('queued', 2);
    sendMessage($tenant, $owner, ['channel' => 'in_app', 'body' => 'Only you', 'audience' => ['type' => 'users', 'user_ids' => [$secretaryId]]])
        ->assertCreated()->assertJsonPath('queued', 1);

    api($tenant, $secretary)->getJson('/api/v1/inbox/unread-count')->assertOk()->assertJsonPath('unread', 2);
    api($tenant, $owner)->getJson('/api/v1/inbox/unread-count')->assertJsonPath('unread', 1);

    $inbox = api($tenant, $secretary)->getJson('/api/v1/inbox?unread=1')->assertOk();
    expect($inbox->json('data'))->toHaveCount(2)->and($inbox->json('data.1.subject'))->toBe('Meeting')->and($inbox->json('data.0.is_read'))->toBeFalse();

    $mine = (int) $inbox->json('data.1.id');
    $ownersMessage = (int) api($tenant, $owner)->getJson('/api/v1/inbox')->json('data.0.id');

    api($tenant, $secretary)->postJson("/api/v1/inbox/{$ownersMessage}/read")->assertNotFound(); // never someone else's
    api($tenant, $secretary)->postJson("/api/v1/inbox/{$mine}/read")->assertOk()->assertJsonPath('data.is_read', true);
    api($tenant, $secretary)->getJson('/api/v1/inbox/unread-count')->assertJsonPath('unread', 1);
    api($tenant, $secretary)->postJson('/api/v1/inbox/read-all')->assertOk()->assertJsonPath('marked', 1);
    api($tenant, $secretary)->getJson('/api/v1/inbox?unread=1')->assertJsonCount(0, 'data');
    api($tenant, $owner)->getJson('/api/v1/inbox/unread-count')->assertJsonPath('unread', 1);
});

it('dispatches queued messages, retries failures up to the limit, and allows manual retry and cancel', function (): void {
    [$tenant, $token, $group] = messagingWorld();
    $send = fn () => sendMessage($tenant, $token, ['channel' => 'sms', 'audience' => ['type' => 'guardians', 'group_id' => $group->id], 'body' => 'Hi'])->assertCreated();
    $first = fn () => (int) api($tenant, $token)->getJson('/api/v1/messages')->json('data.0.id');

    $send();
    expect(app(DispatchMessages::class)->handle())->toBe(['sent' => 1, 'retried' => 0, 'failed' => 0]);
    $sent = $first();
    api($tenant, $token)->getJson("/api/v1/messages/{$sent}")->assertJsonPath('data.status', 'sent');
    api($tenant, $token)->postJson("/api/v1/messages/{$sent}/cancel")->assertUnprocessable();
    expect(app(DispatchMessages::class)->handle()['sent'])->toBe(0); // nothing is sent twice

    config(['messaging.drivers.sms' => FailingMessageDriver::class]);
    $send();
    $failing = $first();

    expect(app(DispatchMessages::class)->handle())->toBe(['sent' => 0, 'retried' => 1, 'failed' => 0])
        ->and(app(DispatchMessages::class)->handle()['retried'])->toBe(1)
        ->and(app(DispatchMessages::class)->handle())->toBe(['sent' => 0, 'retried' => 0, 'failed' => 1]);

    api($tenant, $token)->getJson("/api/v1/messages/{$failing}")->assertJsonPath('data.status', 'failed')
        ->assertJsonPath('data.attempts', 3)->assertJsonPath('data.last_error', 'Gateway down');

    config(['messaging.drivers.sms' => 'log']);
    api($tenant, $token)->postJson("/api/v1/messages/{$failing}/retry")->assertOk()->assertJsonPath('data.status', 'queued')->assertJsonPath('data.attempts', 0);
    api($tenant, $token)->postJson("/api/v1/messages/{$failing}/retry")->assertUnprocessable();
    expect(app(DispatchMessages::class)->handle()['sent'])->toBe(1);

    $send();
    $cancelled = $first();
    api($tenant, $token)->postJson("/api/v1/messages/{$cancelled}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
    expect(app(DispatchMessages::class)->handle()['sent'])->toBe(0);
});

it('alerts the guardians of students with enough unjustified absences, once per week', function (): void {
    [$tenant, $token, $group, [$s1, $s2]] = messagingWorld();
    unjustifiedAbsences($tenant, $group, $s1, [1, 2]);
    unjustifiedAbsences($tenant, $group, $s2, [1]);
    $s3 = makeStudent($tenant, 'Student3', 'Name3');
    enrollNow($tenant, $s3, $group);
    linkGuardian($tenant, $s3, '0661000000', 'Justified');
    unjustifiedAbsences($tenant, $group, $s3, [3, 4], justified: true);
    unjustifiedAbsences($tenant, $group, $s1, [60]); // outside the 30-day window

    api($tenant, $token)->postJson('/api/v1/alerts/absences/run', ['threshold' => 2, 'days' => 30])->assertOk()
        ->assertJsonPath('students', 1)->assertJsonPath('queued', 1)->assertJsonPath('duplicates', 0);

    $message = api($tenant, $token)->getJson('/api/v1/messages?kind=absence_alert')->assertOk()->json('data.0');
    expect($message['body'])->toContain('Student1 Name1')->toContain('2 مرات')->toContain('School-a')
        ->and($message['to'])->toBe('0555123456')->and($message['student_id'])->toBe($s1->id);

    api($tenant, $token)->postJson('/api/v1/alerts/absences/run', ['threshold' => 2])->assertOk()
        ->assertJsonPath('queued', 0)->assertJsonPath('duplicates', 1);

    // with threshold 1 the second student is reached too, but his guardian has no phone
    api($tenant, $token)->postJson('/api/v1/alerts/absences/run', ['threshold' => 1])->assertOk()
        ->assertJsonPath('queued', 0)->assertJsonPath('skipped', 1);
    api($tenant, $token)->postJson('/api/v1/alerts/absences/run', ['threshold' => 0])->assertUnprocessable();
});

it('lets the institution replace the alert text with its own template', function (): void {
    [$tenant, $token, $group, [$s1]] = messagingWorld();
    unjustifiedAbsences($tenant, $group, $s1, [1, 2, 3]);
    api($tenant, $token)->postJson('/api/v1/message-templates', [
        'key' => 'absence_alert', 'name' => 'Absence', 'body' => 'Absent {absences}x in {days} days: {student_name}',
    ])->assertCreated();

    api($tenant, $token)->postJson('/api/v1/alerts/absences/run', ['threshold' => 3, 'days' => 14])->assertOk()->assertJsonPath('queued', 1);

    api($tenant, $token)->getJson('/api/v1/messages?kind=absence_alert')->assertJsonPath('data.0.body', 'Absent 3x in 14 days: Student1 Name1');
});

it('reminds the financially responsible guardians of overdue invoices, once per week', function (): void {
    [$tenant, $token, , [$s1, $s2]] = messagingWorld();
    linkGuardian($tenant, $s1, '0770000000', 'Other', financial: false); // not the payer: must not be reminded
    $invoice = fn (Student $s, int $dueInDays) => api($tenant, $token)->postJson('/api/v1/invoices', [
        'student_id' => $s->id, 'description' => 'Tuition', 'unit_price' => 250000, 'due_date' => now()->addDays($dueInDays)->toDateString(),
    ])->assertCreated();

    $overdue = $invoice($s1, -10)->json('data');
    $invoice($s2, 10); // not due yet

    api($tenant, $token)->postJson('/api/v1/alerts/payments/run')->assertOk()
        ->assertJsonPath('students', 1)->assertJsonPath('queued', 1);

    $message = api($tenant, $token)->getJson('/api/v1/messages?kind=payment_reminder')->assertOk()->json('data');
    expect($message)->toHaveCount(1)
        ->and($message[0]['to'])->toBe('0555123456')
        ->and($message[0]['body'])->toContain('2 500,00')->toContain(now()->subDays(10)->toDateString())->toContain('Student1 Name1');

    api($tenant, $token)->postJson('/api/v1/alerts/payments/run')->assertJsonPath('queued', 0)->assertJsonPath('duplicates', 1);

    // once paid in full there is nothing left to remind
    api($tenant, $token)->postJson('/api/v1/payments', ['student_id' => $s1->id, 'amount' => 250000, 'method' => 'cash'])->assertCreated();
    api($tenant, $token)->postJson('/api/v1/alerts/payments/run')->assertJsonPath('students', 0)->assertJsonPath('queued', 0);
    expect($overdue['outstanding'])->toBe(250000);
});

it('enforces messaging permissions and keeps tenants apart', function (): void {
    [$tenant, $owner, $group] = messagingWorld();
    sendMessage($tenant, $owner, ['channel' => 'sms', 'audience' => ['type' => 'guardians', 'group_id' => $group->id], 'body' => 'Hi'])->assertCreated();
    $messageId = (int) api($tenant, $owner)->getJson('/api/v1/messages')->json('data.0.id');

    $teacher = roleToken($tenant, 'teacher');
    api($tenant, $teacher)->getJson('/api/v1/messages')->assertForbidden();
    sendMessage($tenant, $teacher, ['channel' => 'sms', 'audience' => ['type' => 'guardians', 'group_id' => $group->id], 'body' => 'Hi'])->assertForbidden();
    api($tenant, $teacher)->getJson('/api/v1/message-templates')->assertForbidden();
    api($tenant, $teacher)->postJson('/api/v1/alerts/absences/run')->assertForbidden();
    api($tenant, $teacher)->getJson('/api/v1/inbox')->assertOk()->assertJsonCount(0, 'data');

    $secretary = roleToken($tenant, 'secretary');
    api($tenant, $secretary)->getJson('/api/v1/messages')->assertOk();
    api($tenant, $secretary)->getJson('/api/v1/message-templates')->assertOk();
    api($tenant, $secretary)->postJson('/api/v1/message-templates', ['key' => 'x', 'name' => 'X', 'body' => 'Hi'])->assertForbidden();
    sendMessage($tenant, $secretary, ['channel' => 'sms', 'audience' => ['type' => 'guardians', 'group_id' => $group->id], 'body' => 'Hi'])->assertCreated();
    api($tenant, $secretary)->postJson('/api/v1/alerts/absences/run')->assertOk();

    $accountant = roleToken($tenant, 'accountant');
    api($tenant, $accountant)->postJson('/api/v1/alerts/payments/run')->assertOk();
    api($tenant, $accountant)->postJson('/api/v1/message-templates', ['key' => 'x', 'name' => 'X', 'body' => 'Hi'])->assertForbidden();

    [$other, $otherToken] = ownerSession('school-b');
    api($other, $otherToken)->getJson("/api/v1/messages/{$messageId}")->assertNotFound();
    api($other, $otherToken)->postJson("/api/v1/messages/{$messageId}/cancel")->assertNotFound();
    api($other, $otherToken)->getJson('/api/v1/inbox')->assertOk()->assertJsonCount(0, 'data');
});

it('runs the dispatcher and the alerts for every tenant from the console', function (): void {
    [$tenant, , $group, [$s1]] = messagingWorld();
    unjustifiedAbsences($tenant, $group, $s1, [1, 2]);

    $this->artisan('messages:alerts', ['--threshold' => 2])->assertSuccessful();
    expect(inTenant($tenant, fn () => Message::query()->where('status', 'queued')->count()))->toBe(1);

    $this->artisan('messages:alerts', ['--threshold' => 2])->assertSuccessful(); // idempotent
    expect(inTenant($tenant, fn () => Message::query()->count()))->toBe(1);

    $this->artisan('messages:alerts', ['--channel' => 'in_app'])->assertFailed();

    $this->artisan('messages:dispatch')->assertSuccessful();
    expect(inTenant($tenant, fn () => Message::query()->where('status', 'sent')->count()))->toBe(1);
});
