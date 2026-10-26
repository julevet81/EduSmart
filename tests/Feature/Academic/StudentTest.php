<?php

declare(strict_types=1);

use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;

function studentPayload(array $overrides = []): array
{
    return array_merge([
        'first_name' => 'أمين', 'last_name' => 'بن علي',
        'first_name_latin' => 'Amine', 'last_name_latin' => 'Benali',
        'gender' => 'male', 'birth_date' => '2012-05-14', 'wilaya_code' => 16,
        'phone' => '0661234567',
    ], $overrides);
}

it('numbers students sequentially and independently per tenant', function (): void {
    [$a, $tokenA] = ownerSession('school-a');
    [$b, $tokenB] = ownerSession('school-b');
    $yy = now()->format('y');

    $first = api($a, $tokenA)->postJson('/api/v1/students', studentPayload())->assertCreated();
    $second = api($a, $tokenA)->postJson('/api/v1/students', studentPayload())->assertCreated();
    $other = api($b, $tokenB)->postJson('/api/v1/students', studentPayload())->assertCreated();

    expect($first->json('data.registration_number'))->toBe("{$yy}-00001")
        ->and($second->json('data.registration_number'))->toBe("{$yy}-00002")
        ->and($other->json('data.registration_number'))->toBe("{$yy}-00001");
});

it('never exposes a student of another tenant', function (): void {
    [$a, $tokenA] = ownerSession('school-a');
    [$b] = provisionTenant('school-b');
    $foreign = makeStudent($b);

    api($a, $tokenA)->getJson("/api/v1/students/{$foreign->id}")->assertNotFound();
    api($a, $tokenA)->patchJson("/api/v1/students/{$foreign->id}", ['first_name' => 'Hack'])->assertNotFound();
    api($a, $tokenA)->deleteJson("/api/v1/students/{$foreign->id}")->assertNotFound();
    expect(api($a, $tokenA)->getJson('/api/v1/students')->json('data'))->toBeEmpty();
});

it('validates wilaya, phone and birth date', function (): void {
    [$tenant, $token] = ownerSession();

    api($tenant, $token)->postJson('/api/v1/students', studentPayload([
        'wilaya_code' => 59, 'phone' => '123', 'birth_date' => '2999-01-01',
    ]))->assertStatus(422)->assertJsonValidationErrors(['wilaya_code', 'phone', 'birth_date']);
});

it('encrypts sensitive fields and shows them only with permission', function (): void {
    [$tenant, $ownerToken] = ownerSession();
    $secretaryToken = roleToken($tenant, 'secretary');

    $id = api($tenant, $ownerToken)->postJson('/api/v1/students', studentPayload([
        'medical_notes' => 'Asthme', 'national_id' => '123456789',
    ]))->assertCreated()->assertJsonPath('data.medical_notes', 'Asthme')->json('data.id');

    $raw = DB::table('students')->where('id', $id)->first();
    expect($raw->medical_notes)->not->toContain('Asthme')->and($raw->national_id)->not->toContain('123456789');

    api($tenant, $secretaryToken)->getJson("/api/v1/students/{$id}")
        ->assertOk()->assertJsonMissingPath('data.medical_notes')->assertJsonMissingPath('data.national_id');
});

it('keeps sensitive values out of the audit log', function (): void {
    [$tenant, $token] = ownerSession();

    api($tenant, $token)->postJson('/api/v1/students', studentPayload(['medical_notes' => 'Asthme']))->assertCreated();

    $log = AuditLog::query()->where('event', 'student.created')->where('tenant_id', $tenant->id)->firstOrFail();
    expect($log->new_values)->not->toHaveKey('medical_notes')->and($log->new_values)->toHaveKey('last_name');
});

it('links several guardians to a student with a single primary contact', function (): void {
    [$tenant, $token] = ownerSession();
    $studentId = api($tenant, $token)->postJson('/api/v1/students', studentPayload())->json('data.id');

    $father = api($tenant, $token)->postJson('/api/v1/guardians', ['first_name' => 'Omar', 'last_name' => 'Benali', 'phone' => '0555000001'])->json('data.id');
    $mother = api($tenant, $token)->postJson('/api/v1/guardians', ['first_name' => 'Salima', 'last_name' => 'Benali'])->json('data.id');

    api($tenant, $token)->postJson("/api/v1/students/{$studentId}/guardians", [
        'guardian_id' => $father, 'relationship' => 'father', 'is_primary' => true, 'is_financial_responsible' => true,
    ])->assertOk();
    $response = api($tenant, $token)->postJson("/api/v1/students/{$studentId}/guardians", [
        'guardian_id' => $mother, 'relationship' => 'mother', 'is_primary' => true,
    ])->assertOk();

    $guardians = collect($response->json('data.guardians'));
    expect($guardians)->toHaveCount(2)
        ->and($guardians->where('is_primary', true))->toHaveCount(1)
        ->and($guardians->firstWhere('id', $mother)['relationship'])->toBe('mother');

    api($tenant, $token)->deleteJson("/api/v1/students/{$studentId}/guardians/{$mother}")->assertNoContent();
});

it('rejects linking a guardian of another tenant', function (): void {
    [$a, $tokenA] = ownerSession('school-a');
    [$b, $tokenB] = ownerSession('school-b');

    $studentId = api($a, $tokenA)->postJson('/api/v1/students', studentPayload())->json('data.id');
    $foreignGuardian = api($b, $tokenB)->postJson('/api/v1/guardians', ['first_name' => 'X', 'last_name' => 'Y'])->json('data.id');

    api($a, $tokenA)->postJson("/api/v1/students/{$studentId}/guardians", [
        'guardian_id' => $foreignGuardian, 'relationship' => 'father',
    ])->assertStatus(422)->assertJsonValidationErrors('guardian_id');
});

it('enforces role permissions on students', function (): void {
    [$tenant] = ownerSession();

    $accountant = roleToken($tenant, 'accountant');
    $secretary = roleToken($tenant, 'secretary');
    $teacher = roleToken($tenant, 'teacher');

    api($tenant, $accountant)->getJson('/api/v1/students')->assertOk();
    api($tenant, $accountant)->postJson('/api/v1/students', studentPayload())->assertForbidden();
    api($tenant, $secretary)->postJson('/api/v1/students', studentPayload())->assertCreated();
    api($tenant, $teacher)->getJson('/api/v1/students')->assertForbidden();
});
