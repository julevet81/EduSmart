<?php

declare(strict_types=1);

use App\Models\Absence;
use App\Models\Invoice;
use App\Models\Tenant;
use Illuminate\Testing\TestResponse;

/**
 * A tenant with a monthly plan of 5 000,00 DA (500 000 centimes) assigned to one student starting 3 months ago.
 *
 * @return array{0: Tenant, 1: string, 2: App\Models\Student, 3: int, 4: int}  tenant, token, student, plan id, student-fee id
 */
function financeWorld(array $fee = [], array $plan = []): array
{
    [$tenant, $token] = ownerSession();
    $year = makeCurrentYear($tenant);
    $student = makeStudent($tenant);

    $planId = (int) api($tenant, $token)->postJson('/api/v1/fee-plans', array_merge([
        'academic_year_id' => $year->id, 'name' => 'Monthly tuition', 'billing_mode' => 'monthly', 'amount' => 500000, 'due_day' => 5,
    ], $plan))->assertCreated()->json('data.id');

    $feeId = (int) api($tenant, $token)->postJson('/api/v1/student-fees', array_merge([
        'student_id' => $student->id, 'fee_plan_id' => $planId, 'starts_on' => now()->subMonths(3)->toDateString(),
    ], $fee))->assertCreated()->json('data.id');

    return [$tenant, $token, $student, $planId, $feeId];
}

function bill(Tenant $tenant, string $token, string $month): TestResponse
{
    return api($tenant, $token)->postJson('/api/v1/invoices/generate', ['month' => $month]);
}

function pay(Tenant $tenant, string $token, int $studentId, int $amount, array $extra = []): TestResponse
{
    return api($tenant, $token)->postJson('/api/v1/payments', array_merge([
        'student_id' => $studentId, 'amount' => $amount, 'method' => 'cash',
    ], $extra));
}

it('creates fee plans in centimes and rejects invalid data and foreign references', function (): void {
    [$tenant, $token] = ownerSession();
    [$other] = ownerSession('school-b');
    $year = makeCurrentYear($tenant);
    $foreignYear = makeCurrentYear($other);

    api($tenant, $token)->postJson('/api/v1/fee-plans', [
        'academic_year_id' => $year->id, 'name' => 'Math', 'billing_mode' => 'per_lesson', 'amount' => 80000,
    ])->assertCreated()->assertJsonPath('data.amount', 80000)->assertJsonPath('data.billing_mode', 'per_lesson');

    api($tenant, $token)->postJson('/api/v1/fee-plans', [
        'academic_year_id' => $year->id, 'name' => 'X', 'billing_mode' => 'weekly', 'amount' => 1000,
    ])->assertUnprocessable()->assertJsonValidationErrors('billing_mode');

    api($tenant, $token)->postJson('/api/v1/fee-plans', [
        'academic_year_id' => $year->id, 'name' => 'X', 'billing_mode' => 'monthly', 'amount' => 10.5,
    ])->assertUnprocessable()->assertJsonValidationErrors('amount');

    api($tenant, $token)->postJson('/api/v1/fee-plans', [
        'academic_year_id' => $foreignYear->id, 'name' => 'X', 'billing_mode' => 'monthly', 'amount' => 1000,
    ])->assertUnprocessable()->assertJsonValidationErrors('academic_year_id');
});

it('bills a month with a percentage discount and never bills the same period twice', function (): void {
    [$tenant, $token, $student] = financeWorld(['discount_type' => 'percent', 'discount_value' => 10]);
    $month = now()->format('Y-m');

    bill($tenant, $token, $month)->assertOk()->assertJsonPath('created', 1)->assertJsonPath('total_amount', 450000);
    bill($tenant, $token, $month)->assertOk()->assertJsonPath('created', 0)->assertJsonPath('skipped', 1);

    $invoice = api($tenant, $token)->getJson("/api/v1/invoices?student_id={$student->id}")->assertOk()->json('data.0');

    expect($invoice['unit_price'])->toBe(500000)
        ->and($invoice['discount_amount'])->toBe(50000)
        ->and($invoice['total_amount'])->toBe(450000)
        ->and($invoice['status'])->toBe('open')
        ->and($invoice['number'])->toBe('INV-'.now()->format('Y').'-000001');
});

it('settles the oldest invoices first and keeps the surplus as credit', function (): void {
    [$tenant, $token, $student] = financeWorld();
    bill($tenant, $token, now()->subMonthNoOverflow()->format('Y-m'));
    bill($tenant, $token, now()->format('Y-m'));

    $payment = pay($tenant, $token, $student->id, 700000)->assertCreated()
        ->assertJsonPath('data.receipt_number', 'RC-'.now()->format('Y').'-000001')
        ->assertJsonPath('data.unallocated', 0);

    $statuses = collect(api($tenant, $token)->getJson("/api/v1/invoices?student_id={$student->id}")->json('data'))
        ->sortBy('id')->pluck('status')->values()->all();

    expect($statuses)->toBe(['paid', 'partially_paid'])
        ->and($payment->json('data.allocations'))->toHaveCount(2);

    // 3 000 DA are still owed: the other 3 000 DA stay as an advance
    pay($tenant, $token, $student->id, 600000)->assertCreated()->assertJsonPath('data.unallocated', 300000);

    api($tenant, $token)->getJson("/api/v1/students/{$student->id}/account")->assertOk()
        ->assertJsonPath('outstanding', 0)->assertJsonPath('credit', 300000)->assertJsonPath('received', 1300000);
});

it('replays a payment with the same idempotency key without charging twice', function (): void {
    [$tenant, $token, $student] = financeWorld();
    bill($tenant, $token, now()->format('Y-m'));

    $first = pay($tenant, $token, $student->id, 200000, ['idempotency_key' => 'abc-1'])->assertCreated();
    $again = pay($tenant, $token, $student->id, 200000, ['idempotency_key' => 'abc-1'])->assertOk();

    expect($again->json('data.receipt_number'))->toBe($first->json('data.receipt_number'));

    pay($tenant, $token, $student->id, 999, ['idempotency_key' => 'abc-1'])->assertStatus(409);

    api($tenant, $token)->getJson("/api/v1/payments?student_id={$student->id}")->assertJsonCount(1, 'data');
});

it('rejects explicit allocations that exceed the invoice or the payment', function (): void {
    [$tenant, $token, $student] = financeWorld();
    bill($tenant, $token, now()->format('Y-m'));
    $invoiceId = (int) api($tenant, $token)->getJson('/api/v1/invoices')->json('data.0.id');

    pay($tenant, $token, $student->id, 900000, ['allocations' => [['invoice_id' => $invoiceId, 'amount' => 600000]]])
        ->assertUnprocessable();

    pay($tenant, $token, $student->id, 100000, ['allocations' => [['invoice_id' => $invoiceId, 'amount' => 200000]]])
        ->assertUnprocessable();

    pay($tenant, $token, $student->id, 300000, ['allocations' => [['invoice_id' => $invoiceId, 'amount' => 300000]]])
        ->assertCreated();

    api($tenant, $token)->getJson("/api/v1/invoices/{$invoiceId}")->assertJsonPath('data.status', 'partially_paid')
        ->assertJsonPath('data.outstanding', 200000);
});

it('reverses a payment, reopens the invoice and refuses a second reversal', function (): void {
    [$tenant, $token, $student] = financeWorld();
    bill($tenant, $token, now()->format('Y-m'));
    $paymentId = (int) pay($tenant, $token, $student->id, 500000)->assertCreated()->json('data.id');

    api($tenant, $token)->postJson("/api/v1/payments/{$paymentId}/reverse", ['reason' => 'x'])->assertUnprocessable();
    api($tenant, $token)->postJson("/api/v1/payments/{$paymentId}/reverse", ['reason' => 'Wrong student'])
        ->assertOk()->assertJsonPath('data.status', 'reversed');

    api($tenant, $token)->getJson('/api/v1/invoices')->assertJsonPath('data.0.status', 'open')->assertJsonPath('data.0.paid_amount', 0);
    api($tenant, $token)->postJson("/api/v1/payments/{$paymentId}/reverse", ['reason' => 'Again'])->assertUnprocessable();
});

it('voids only unpaid invoices', function (): void {
    [$tenant, $token, $student] = financeWorld();
    bill($tenant, $token, now()->format('Y-m'));
    $invoiceId = (int) api($tenant, $token)->getJson('/api/v1/invoices')->json('data.0.id');
    $paymentId = (int) pay($tenant, $token, $student->id, 100000)->json('data.id');

    api($tenant, $token)->postJson("/api/v1/invoices/{$invoiceId}/void", ['reason' => 'Mistake'])->assertUnprocessable();

    api($tenant, $token)->postJson("/api/v1/payments/{$paymentId}/reverse", ['reason' => 'Refund']);
    api($tenant, $token)->postJson("/api/v1/invoices/{$invoiceId}/void", ['reason' => 'Mistake'])
        ->assertOk()->assertJsonPath('data.status', 'void')->assertJsonPath('data.outstanding', 0);

    pay($tenant, $token, $student->id, 100000)->assertCreated()->assertJsonPath('data.unallocated', 100000);
});

it('keeps issued invoices and payments immutable', function (): void {
    [$tenant, $token, $student] = financeWorld();
    bill($tenant, $token, now()->format('Y-m'));
    $paymentId = (int) pay($tenant, $token, $student->id, 100000)->json('data.id');

    expect(fn () => inTenant($tenant, fn () => Invoice::query()->first()->update(['total_amount' => 1])))->toThrow(LogicException::class)
        ->and(fn () => inTenant($tenant, fn () => Invoice::query()->first()->delete()))->toThrow(LogicException::class)
        ->and(fn () => inTenant($tenant, fn () => App\Models\Payment::query()->findOrFail($paymentId)->update(['amount' => 1])))->toThrow(LogicException::class)
        ->and(fn () => inTenant($tenant, fn () => App\Models\Payment::query()->findOrFail($paymentId)->delete()))->toThrow(LogicException::class);
});

it('bills per completed lesson and skips absences unless the plan bills them', function (): void {
    [$tenant, $token] = ownerSession();
    $year = makeCurrentYear($tenant);
    $group = makeGroup($tenant, $year);
    $teacher = makeTeacher($tenant);
    $student = makeStudent($tenant);
    enrollNow($tenant, $student, $group, now()->startOfMonth()->subDays(5)->toDateString());

    $planId = (int) api($tenant, $token)->postJson('/api/v1/fee-plans', [
        'academic_year_id' => $year->id, 'name' => 'Per lesson', 'billing_mode' => 'per_lesson', 'amount' => 100000,
    ])->json('data.id');
    api($tenant, $token)->postJson('/api/v1/student-fees', [
        'student_id' => $student->id, 'fee_plan_id' => $planId, 'starts_on' => now()->startOfMonth()->toDateString(),
    ])->assertCreated();

    $lessons = [];
    foreach ([0, 1, 2] as $i) {
        $lessons[] = makeLesson($tenant, $group, $teacher, now()->startOfMonth()->addDays($i)->toDateString(), '08:00', '09:00', null, ['status' => 'completed']);
    }
    makeLesson($tenant, $group, $teacher, now()->startOfMonth()->addDays(5)->toDateString(), '08:00', '09:00', null, ['status' => 'cancelled']);
    inTenant($tenant, fn () => Absence::query()->create(['lesson_id' => $lessons[0]->id, 'student_id' => $student->id, 'type' => 'absent']));

    bill($tenant, $token, now()->format('Y-m'))->assertOk()->assertJsonPath('created', 1)->assertJsonPath('total_amount', 200000);
});

it('bills a term plan once per started term', function (): void {
    [$tenant, $token] = ownerSession();
    $year = makeCurrentYear($tenant);
    $student = makeStudent($tenant);

    api($tenant, $token)->postJson("/api/v1/academic-years/{$year->id}/terms", [
        'number' => 1, 'name' => 'Term 1', 'starts_on' => now()->subMonth()->toDateString(), 'ends_on' => now()->addMonths(2)->toDateString(),
    ])->assertCreated();

    $planId = (int) api($tenant, $token)->postJson('/api/v1/fee-plans', [
        'academic_year_id' => $year->id, 'name' => 'Term fee', 'billing_mode' => 'term', 'amount' => 1500000,
    ])->json('data.id');
    api($tenant, $token)->postJson('/api/v1/student-fees', [
        'student_id' => $student->id, 'fee_plan_id' => $planId, 'starts_on' => now()->subMonths(2)->toDateString(),
    ])->assertCreated();

    bill($tenant, $token, now()->format('Y-m'))->assertJsonPath('created', 1);
    bill($tenant, $token, now()->addMonthNoOverflow()->format('Y-m'))->assertJsonPath('created', 0)->assertJsonPath('skipped', 1);
});

it('assigns a plan to a whole group once', function (): void {
    [$tenant, $token] = ownerSession();
    $year = makeCurrentYear($tenant);
    $group = makeGroup($tenant, $year);
    foreach (['A', 'B'] as $n) {
        enrollNow($tenant, makeStudent($tenant, $n), $group);
    }
    $planId = (int) api($tenant, $token)->postJson('/api/v1/fee-plans', [
        'academic_year_id' => $year->id, 'name' => 'Tuition', 'billing_mode' => 'monthly', 'amount' => 300000,
    ])->json('data.id');

    $body = ['group_id' => $group->id, 'starts_on' => now()->toDateString()];
    api($tenant, $token)->postJson("/api/v1/fee-plans/{$planId}/assign-group", $body)->assertOk()->assertJsonPath('assigned', 2);
    api($tenant, $token)->postJson("/api/v1/fee-plans/{$planId}/assign-group", $body)->assertOk()->assertJsonPath('assigned', 0);
});

it('applies stored credit to later invoices', function (): void {
    [$tenant, $token, $student] = financeWorld();
    pay($tenant, $token, $student->id, 500000)->assertCreated()->assertJsonPath('data.unallocated', 500000);
    bill($tenant, $token, now()->format('Y-m'));

    api($tenant, $token)->postJson("/api/v1/students/{$student->id}/allocate-credit")
        ->assertOk()->assertJsonPath('applied', 500000)->assertJsonPath('credit', 0)->assertJsonPath('outstanding', 0);
});

it('reports collections, billing and receivables', function (): void {
    [$tenant, $token, $student] = financeWorld();
    bill($tenant, $token, now()->format('Y-m'));
    pay($tenant, $token, $student->id, 200000)->assertCreated();
    pay($tenant, $token, $student->id, 100000, ['method' => 'cheque'])->assertCreated();

    $from = now()->startOfMonth()->toDateString();
    $to = now()->endOfMonth()->toDateString();

    api($tenant, $token)->getJson("/api/v1/reports/finance/summary?from={$from}&to={$to}")->assertOk()
        ->assertJsonPath('collected_total', 300000)->assertJsonPath('billed_total', 500000)->assertJsonPath('outstanding_total', 200000);

    api($tenant, $token)->getJson('/api/v1/reports/finance/receivables')->assertOk()
        ->assertJsonPath('data.0.student_id', $student->id)->assertJsonPath('data.0.due', 200000);
});

it('enforces finance permissions and tenant isolation', function (): void {
    [$tenant, $token, $student] = financeWorld();
    bill($tenant, $token, now()->format('Y-m'));
    $invoiceId = (int) api($tenant, $token)->getJson('/api/v1/invoices')->json('data.0.id');
    $paymentId = (int) pay($tenant, $token, $student->id, 100000)->json('data.id');

    $secretary = roleToken($tenant, 'secretary');
    pay($tenant, $secretary, $student->id, 100000)->assertCreated();
    api($tenant, $secretary)->postJson("/api/v1/payments/{$paymentId}/reverse", ['reason' => 'nope'])->assertForbidden();
    api($tenant, $secretary)->getJson('/api/v1/fee-plans')->assertForbidden();
    api($tenant, $secretary)->postJson('/api/v1/invoices/generate', ['month' => now()->format('Y-m')])->assertForbidden();

    $teacher = roleToken($tenant, 'teacher');
    api($tenant, $teacher)->getJson('/api/v1/invoices')->assertForbidden();
    api($tenant, $teacher)->getJson('/api/v1/payments')->assertForbidden();

    $accountant = roleToken($tenant, 'accountant');
    api($tenant, $accountant)->getJson('/api/v1/invoices')->assertOk();
    api($tenant, $accountant)->postJson("/api/v1/payments/{$paymentId}/reverse", ['reason' => 'nope'])->assertForbidden();

    [$other, $otherToken] = ownerSession('school-b');
    api($other, $otherToken)->getJson("/api/v1/invoices/{$invoiceId}")->assertNotFound();
    api($other, $otherToken)->getJson("/api/v1/payments/{$paymentId}")->assertNotFound();
    pay($other, $otherToken, $student->id, 100000)->assertUnprocessable();
});
