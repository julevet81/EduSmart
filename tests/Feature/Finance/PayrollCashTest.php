<?php

declare(strict_types=1);

use App\Models\CashClosing;
use App\Models\Expense;
use App\Models\Payslip;
use App\Models\StaffMember;
use App\Models\Tenant;
use Illuminate\Testing\TestResponse;

/** @return array{0: Tenant, 1: string, 2: int} tenant, owner token, expense category id */
function expenseWorld(string $slug = 'school-a'): array
{
    [$tenant, $token] = ownerSession($slug);
    $categoryId = (int) api($tenant, $token)->postJson('/api/v1/expense-categories', ['name' => 'Electricity'])->assertCreated()->json('data.id');

    return [$tenant, $token, $categoryId];
}

function spend(Tenant $tenant, string $token, int $categoryId, int|float $amount, array $extra = []): TestResponse{
    return api($tenant, $token)->postJson('/api/v1/expenses', array_merge([
        'expense_category_id' => $categoryId, 'description' => 'Bill', 'amount' => $amount, 'method' => 'cash',
    ], $extra));
}

function cashIn(Tenant $tenant, string $token, int $amount, array $extra = []): TestResponse
{
    $student = makeStudent($tenant, 'Pay'.fake()->unique()->numerify('###'));

    return api($tenant, $token)->postJson('/api/v1/payments', array_merge([
        'student_id' => $student->id, 'amount' => $amount, 'method' => 'cash',
    ], $extra));
}

/** Teacher with a monthly contract of 10 000,00 DA started last month. @return array{0: int, 1: App\Models\Teacher} */
function paidTeacher(Tenant $tenant, string $token, int $monthly = 1000000): array
{
    $teacher = makeTeacher($tenant);
    $contract = api($tenant, $token)->postJson('/api/v1/salary-contracts', [
        'payee_type' => 'teacher', 'payee_id' => $teacher->id, 'type' => 'monthly', 'amount' => $monthly,
        'starts_on' => now()->subMonth()->toDateString(),
    ])->assertCreated()->json('data.id');

    return [(int) $contract, $teacher];
}

function genPayslips(Tenant $tenant, string $token, ?string $month = null): TestResponse
{
    return api($tenant, $token)->postJson('/api/v1/payslips/generate', ['month' => $month ?? now()->format('Y-m')]);
}

it('records expenses in centimes, replays idempotent requests and reverses mistakes', function (): void {
    [$tenant, $token, $categoryId] = expenseWorld();

    $first = spend($tenant, $token, $categoryId, 1500000, ['idempotency_key' => 'k1'])->assertCreated()
        ->assertJsonPath('data.amount', 1500000)->assertJsonPath('data.category', 'Electricity');
    spend($tenant, $token, $categoryId, 1500000, ['idempotency_key' => 'k1'])->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
    spend($tenant, $token, $categoryId, 1, ['idempotency_key' => 'k1'])->assertStatus(409);

    spend($tenant, $token, $categoryId, 10.5)->assertUnprocessable()->assertJsonValidationErrors('amount');
    spend($tenant, $token, 999999, 1000)->assertUnprocessable()->assertJsonValidationErrors('expense_category_id');

    $id = $first->json('data.id');
    api($tenant, $token)->postJson("/api/v1/expenses/{$id}/reverse", ['reason' => 'Duplicate'])->assertOk()->assertJsonPath('data.status', 'reversed');
    api($tenant, $token)->postJson("/api/v1/expenses/{$id}/reverse", ['reason' => 'Again'])->assertUnprocessable();

    expect(fn () => inTenant($tenant, fn () => Expense::query()->findOrFail($id)->update(['amount' => 1])))->toThrow(LogicException::class)
        ->and(fn () => inTenant($tenant, fn () => Expense::query()->findOrFail($id)->delete()))->toThrow(LogicException::class);
});

it('refuses to delete a category that has expenses and rejects inactive ones', function (): void {
    [$tenant, $token, $categoryId] = expenseWorld();
    spend($tenant, $token, $categoryId, 1000)->assertCreated();

    api($tenant, $token)->deleteJson("/api/v1/expense-categories/{$categoryId}")->assertStatus(409);

    api($tenant, $token)->patchJson("/api/v1/expense-categories/{$categoryId}", ['is_active' => false])->assertOk();
    spend($tenant, $token, $categoryId, 1000)->assertUnprocessable();
});

it('validates salary contracts: payee must exist, staff are monthly only', function (): void {
    [$tenant, $token] = ownerSession();
    [$other] = ownerSession('school-b');
    $foreign = makeTeacher($other);
    $staffId = (int) api($tenant, $token)->postJson('/api/v1/staff', ['first_name' => 'Sara', 'last_name' => 'Amrani', 'position' => 'Secretary'])
        ->assertCreated()->json('data.id');

    $body = ['payee_type' => 'teacher', 'payee_id' => $foreign->id, 'type' => 'monthly', 'amount' => 100000, 'starts_on' => '2026-09-01'];
    api($tenant, $token)->postJson('/api/v1/salary-contracts', $body)->assertUnprocessable();

    api($tenant, $token)->postJson('/api/v1/salary-contracts', ['payee_type' => 'staff', 'payee_id' => $staffId, 'type' => 'per_hour'] + $body)
        ->assertUnprocessable();
    api($tenant, $token)->postJson('/api/v1/salary-contracts', ['payee_type' => 'staff', 'payee_id' => $staffId, 'type' => 'monthly'] + $body)
        ->assertCreated()->assertJsonPath('data.payee_type', 'staff');
});

it('generates payslips from fixed pay and completed lessons, once per month', function (): void {
    [$tenant, $token] = ownerSession();
    [, $teacher] = paidTeacher($tenant, $token);
    api($tenant, $token)->postJson('/api/v1/salary-contracts', [
        'payee_type' => 'teacher', 'payee_id' => $teacher->id, 'type' => 'per_hour', 'amount' => 100000,
        'starts_on' => now()->subMonth()->toDateString(),
    ])->assertCreated();

    $group = makeGroup($tenant, makeCurrentYear($tenant));
    foreach ([0, 1] as $i) {
        makeLesson($tenant, $group, $teacher, now()->startOfMonth()->addDays($i)->toDateString(), '08:00', '09:30', null, ['status' => 'completed']);
    }
    makeLesson($tenant, $group, $teacher, now()->startOfMonth()->addDays(3)->toDateString(), '08:00', '09:30', null, ['status' => 'cancelled']);

    // 2 completed lessons x 90 min = 3 h at 1 000,00 DA/h
    genPayslips($tenant, $token)->assertOk()->assertJsonPath('created', 1)->assertJsonPath('total_net', 1300000);
    genPayslips($tenant, $token)->assertOk()->assertJsonPath('created', 0)->assertJsonPath('skipped', 1);

    api($tenant, $token)->getJson('/api/v1/payslips?month='.now()->format('Y-m'))->assertOk()
        ->assertJsonPath('data.0.fixed_amount', 1000000)
        ->assertJsonPath('data.0.variable_amount', 300000)
        ->assertJsonPath('data.0.lessons_count', 2)
        ->assertJsonPath('data.0.lessons_minutes', 180)
        ->assertJsonPath('data.0.net_amount', 1300000)
        ->assertJsonPath('data.0.status', 'draft')
        ->assertJsonPath('data.0.payee_name', 'Karim Haddad')
        ->assertJsonPath('data.0.number', 'PS-'.now()->format('Y').'-000001');
});

it('settles advances, deductions and bonuses on the payslip and keeps what does not fit', function (): void {
    [$tenant, $token] = ownerSession();
    [, $teacher] = paidTeacher($tenant, $token);
    $adjust = fn (string $kind, int $amount, array $extra = []) => api($tenant, $token)->postJson('/api/v1/payroll-adjustments', array_merge([
        'payee_type' => 'teacher', 'payee_id' => $teacher->id, 'kind' => $kind, 'amount' => $amount,
    ], $extra));

    $adjust('advance', 200000)->assertUnprocessable(); // an advance needs a method
    $adjust('advance', 200000, ['method' => 'cash'])->assertCreated();
    $adjust('bonus', 50000)->assertCreated();
    $adjust('deduction', 30000)->assertCreated();
    $adjust('advance', 5000000, ['method' => 'bank_transfer'])->assertCreated(); // far more than the salary

    genPayslips($tenant, $token)->assertJsonPath('total_net', 820000);

    $payslip = api($tenant, $token)->getJson('/api/v1/payslips')->json('data.0');
    expect($payslip['advance_amount'])->toBe(200000)->and($payslip['bonus_amount'])->toBe(50000)->and($payslip['deduction_amount'])->toBe(30000);

    // the oversized advance waits for a later payslip
    expect(api($tenant, $token)->getJson('/api/v1/payroll-adjustments?pending=1')->json('data'))->toHaveCount(1);
});

it('pays a payslip once, reverses it and lets the adjustments be taken again', function (): void {
    [$tenant, $token] = ownerSession();
    [, $teacher] = paidTeacher($tenant, $token);
    $adjustmentId = (int) api($tenant, $token)->postJson('/api/v1/payroll-adjustments', [
        'payee_type' => 'teacher', 'payee_id' => $teacher->id, 'kind' => 'deduction', 'amount' => 100000,
    ])->json('data.id');

    genPayslips($tenant, $token);
    $id = (int) api($tenant, $token)->getJson('/api/v1/payslips')->json('data.0.id');

    // an adjustment already used by a payslip cannot be cancelled
    api($tenant, $token)->postJson("/api/v1/payroll-adjustments/{$adjustmentId}/cancel", ['reason' => 'Oops'])->assertUnprocessable();
    api($tenant, $token)->postJson("/api/v1/payslips/{$id}/reverse", ['reason' => 'Not paid yet'])->assertUnprocessable();

    api($tenant, $token)->postJson("/api/v1/payslips/{$id}/pay", ['method' => 'bank_transfer'])->assertOk()
        ->assertJsonPath('data.status', 'paid')->assertJsonPath('data.net_amount', 900000);
    api($tenant, $token)->postJson("/api/v1/payslips/{$id}/pay", ['method' => 'cash'])->assertUnprocessable();
    api($tenant, $token)->postJson("/api/v1/payslips/{$id}/void", ['reason' => 'Too late'])->assertUnprocessable();

    api($tenant, $token)->postJson("/api/v1/payslips/{$id}/reverse", ['reason' => 'Wrong account'])->assertOk()->assertJsonPath('data.status', 'reversed');

    genPayslips($tenant, $token)->assertJsonPath('created', 1)->assertJsonPath('total_net', 900000);

    expect(fn () => inTenant($tenant, fn () => Payslip::query()->findOrFail($id)->update(['net_amount' => 1])))->toThrow(LogicException::class);
});

it('voids a draft payslip and regenerates it', function (): void {
    [$tenant, $token] = ownerSession();
    paidTeacher($tenant, $token);

    genPayslips($tenant, $token)->assertJsonPath('created', 1);
    $id = (int) api($tenant, $token)->getJson('/api/v1/payslips')->json('data.0.id');

    api($tenant, $token)->postJson("/api/v1/payslips/{$id}/void", ['reason' => 'Contract fixed'])->assertOk()->assertJsonPath('data.status', 'void');
    genPayslips($tenant, $token)->assertJsonPath('created', 1);
    api($tenant, $token)->getJson('/api/v1/payslips')->assertJsonCount(2, 'data');
});

it('computes the expected cash from cash movements only and chains closings', function (): void {
    [$tenant, $token, $categoryId] = expenseWorld();
    $yesterday = now()->subDay()->toDateString();
    $today = now()->toDateString();

    cashIn($tenant, $token, 100000, ['paid_on' => $yesterday])->assertCreated();
    cashIn($tenant, $token, 999999, ['paid_on' => $yesterday, 'method' => 'cheque'])->assertCreated(); // not cash
    spend($tenant, $token, $categoryId, 70000, ['paid_on' => $yesterday, 'method' => 'bank_transfer'])->assertCreated(); // not cash

    api($tenant, $token)->getJson("/api/v1/cash/position?date={$yesterday}")->assertOk()
        ->assertJsonPath('cash_in', 100000)->assertJsonPath('cash_out', 0)->assertJsonPath('expected_balance', 100000);

    api($tenant, $token)->postJson('/api/v1/cash/closings', ['date' => $yesterday, 'counted_balance' => 95000, 'note' => 'Short'])
        ->assertCreated()->assertJsonPath('data.expected_balance', 100000)->assertJsonPath('data.difference', -5000);

    // yesterday is closed: no more cash movement may be dated on or before it
    spend($tenant, $token, $categoryId, 1000, ['paid_on' => $yesterday])->assertUnprocessable();
    cashIn($tenant, $token, 1000, ['paid_on' => $yesterday])->assertUnprocessable();
    spend($tenant, $token, $categoryId, 1000, ['paid_on' => $yesterday, 'method' => 'cheque'])->assertCreated();
    api($tenant, $token)->postJson('/api/v1/cash/closings', ['date' => $yesterday, 'counted_balance' => 1])->assertUnprocessable();

    // today starts from what was COUNTED yesterday
    spend($tenant, $token, $categoryId, 30000, ['paid_on' => $today])->assertCreated();
    api($tenant, $token)->getJson('/api/v1/cash/position')->assertOk()
        ->assertJsonPath('opening_balance', 95000)->assertJsonPath('cash_out', 30000)->assertJsonPath('expected_balance', 65000);

    api($tenant, $token)->postJson('/api/v1/cash/closings', ['counted_balance' => 65000])->assertCreated()->assertJsonPath('data.difference', 0);
    api($tenant, $token)->getJson('/api/v1/cash/closings')->assertOk()->assertJsonCount(2, 'data');

    // today is closed: reversing a cash expense would change the counted past
    $expenseId = (int) Expense::query()->withoutGlobalScopes()->where('amount', 30000)->value('id');
    api($tenant, $token)->postJson("/api/v1/expenses/{$expenseId}/reverse", ['reason' => 'Mistake'])->assertUnprocessable();

    expect(fn () => inTenant($tenant, fn () => CashClosing::query()->first()->update(['counted_balance' => 1])))->toThrow(LogicException::class);
});

it('counts salary cash, advances and refunds in the drawer', function (): void {
    [$tenant, $token] = ownerSession();
    [, $teacher] = paidTeacher($tenant, $token, 400000);

    $paymentId = (int) cashIn($tenant, $token, 1000000)->assertCreated()->json('data.id'); // +10 000
    api($tenant, $token)->postJson('/api/v1/payroll-adjustments', [
        'payee_type' => 'teacher', 'payee_id' => $teacher->id, 'kind' => 'advance', 'amount' => 100000, 'method' => 'cash',
    ])->assertCreated(); // -1 000

    genPayslips($tenant, $token);
    $id = (int) api($tenant, $token)->getJson('/api/v1/payslips')->json('data.0.id'); // net 3 000
    api($tenant, $token)->postJson("/api/v1/payslips/{$id}/pay", ['method' => 'cash'])->assertOk(); // -3 000

    api($tenant, $token)->getJson('/api/v1/cash/position')->assertJsonPath('expected_balance', 600000);

    // a refund (reversal) is cash leaving today
    api($tenant, $token)->postJson("/api/v1/payments/{$paymentId}/reverse", ['reason' => 'Refund'])->assertOk();
    api($tenant, $token)->getJson('/api/v1/cash/position')->assertJsonPath('cash_in', 1000000)->assertJsonPath('cash_out', 1400000)
        ->assertJsonPath('expected_balance', -400000);
});

it('reports the result of a period on a cash basis', function (): void {
    [$tenant, $token, $categoryId] = expenseWorld();
    [, $teacher] = paidTeacher($tenant, $token, 300000);

    cashIn($tenant, $token, 500000)->assertCreated();
    cashIn($tenant, $token, 200000, ['method' => 'cheque'])->assertCreated();
    spend($tenant, $token, $categoryId, 100000)->assertCreated();
    $mistake = (int) spend($tenant, $token, $categoryId, 90000, ['method' => 'cheque'])->json('data.id');
    api($tenant, $token)->postJson("/api/v1/expenses/{$mistake}/reverse", ['reason' => 'Duplicate']);

    genPayslips($tenant, $token);
    $id = (int) api($tenant, $token)->getJson('/api/v1/payslips')->json('data.0.id');
    api($tenant, $token)->postJson("/api/v1/payslips/{$id}/pay", ['method' => 'bank_transfer'])->assertOk();

    $from = now()->startOfMonth()->toDateString();
    $to = now()->endOfMonth()->toDateString();

    api($tenant, $token)->getJson("/api/v1/reports/finance/profit-loss?from={$from}&to={$to}")->assertOk()
        ->assertJsonPath('collected', 700000)
        ->assertJsonPath('expenses', 100000)
        ->assertJsonPath('salaries', 300000)
        ->assertJsonPath('result', 300000)
        ->assertJsonPath('expenses_by_category.0.category', 'Electricity')
        ->assertJsonPath('by_month.0.month', now()->format('Y-m'))
        ->assertJsonPath('by_month.0.result', 300000);
});

it('enforces payroll, expense and cash permissions', function (): void {
    [$tenant, $token, $categoryId] = expenseWorld();
    $expenseId = (int) spend($tenant, $token, $categoryId, 1000)->json('data.id');
    paidTeacher($tenant, $token);
    genPayslips($tenant, $token);
    $payslipId = (int) api($tenant, $token)->getJson('/api/v1/payslips')->json('data.0.id');

    foreach (['secretary', 'teacher'] as $role) {
        $t = roleToken($tenant, $role);
        api($tenant, $t)->getJson('/api/v1/payslips')->assertForbidden();
        api($tenant, $t)->getJson('/api/v1/expenses')->assertForbidden();
        api($tenant, $t)->getJson('/api/v1/cash/position')->assertForbidden();
        api($tenant, $t)->getJson('/api/v1/salary-contracts')->assertForbidden();
    }

    $accountant = roleToken($tenant, 'accountant');
    spend($tenant, $accountant, $categoryId, 2000)->assertCreated();
    api($tenant, $accountant)->postJson("/api/v1/expenses/{$expenseId}/reverse", ['reason' => 'nope'])->assertForbidden();
    api($tenant, $accountant)->postJson("/api/v1/payslips/{$payslipId}/pay", ['method' => 'cash'])->assertOk();
    api($tenant, $accountant)->postJson("/api/v1/payslips/{$payslipId}/reverse", ['reason' => 'nope'])->assertForbidden();
    api($tenant, $accountant)->getJson('/api/v1/reports/finance/profit-loss?from=2026-01-01&to=2026-12-31')->assertOk();

    $manager = roleToken($tenant, 'manager');
    api($tenant, $manager)->postJson("/api/v1/payslips/{$payslipId}/reverse", ['reason' => 'Manager decision'])->assertOk();
    api($tenant, $manager)->postJson("/api/v1/expenses/{$expenseId}/reverse", ['reason' => 'Manager decision'])->assertOk();
});

it('keeps expenses, payslips and cash of another tenant out of reach', function (): void {
    [$tenant, $token, $categoryId] = expenseWorld();
    $expenseId = (int) spend($tenant, $token, $categoryId, 1000)->json('data.id');
    paidTeacher($tenant, $token);
    genPayslips($tenant, $token);
    $payslipId = (int) api($tenant, $token)->getJson('/api/v1/payslips')->json('data.0.id');
    api($tenant, $token)->postJson('/api/v1/cash/closings', ['counted_balance' => 0])->assertCreated();

    [$other, $otherToken] = ownerSession('school-b');

    api($other, $otherToken)->getJson("/api/v1/expenses/{$expenseId}")->assertNotFound();
    api($other, $otherToken)->getJson("/api/v1/payslips/{$payslipId}")->assertNotFound();
    api($other, $otherToken)->postJson("/api/v1/payslips/{$payslipId}/pay", ['method' => 'cash'])->assertNotFound();
    api($other, $otherToken)->getJson('/api/v1/cash/closings')->assertOk()->assertJsonCount(0, 'data');
    spend($other, $otherToken, $categoryId, 1000)->assertUnprocessable();

    expect(inTenant($other, fn () => StaffMember::query()->count()))->toBe(0);
});
