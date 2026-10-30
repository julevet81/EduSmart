<?php

declare(strict_types=1);

use App\Models\Guardian;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Models\Student;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    $GLOBALS['platform_session'] = null; // every test starts with an empty database: no token survives
});

/**
 * A platform token. The login is rate-limited (5 per minute), so one token is reused for a few minutes
 * of (test) time and a new one is requested after travelling in time.
 */
function platformSession(): string
{
    $cache = $GLOBALS['platform_session'] ?? null;
    $age = $cache === null ? null : $cache['at']->diffInMinutes(now(), false);

    if ($cache === null || $age < 0 || $age >= 5) {
        PlatformAdmin::query()->firstOrCreate(['email' => 'root@platform.test'], ['name' => 'Root', 'password' => TEST_PASSWORD, 'is_active' => true]);
        $cache = [
            'at' => now(),
            'token' => (string) api()->postJson('/api/v1/platform/auth/login', ['email' => 'root@platform.test', 'password' => TEST_PASSWORD])->assertOk()->json('token'),
        ];
        $GLOBALS['platform_session'] = $cache;
    }

    return $cache['token'];
}

function payFor(Tenant $tenant, array $body = []): TestResponse
{
    return api(null, platformSession())->postJson("/api/v1/platform/tenants/{$tenant->id}/subscriptions", array_merge([
        'plan' => 'starter', 'months' => 1, 'method' => 'ccp',
    ], $body));
}

function coverage(Tenant $tenant): array
{
    return api(null, platformSession())->getJson("/api/v1/platform/tenants/{$tenant->id}/subscription")->assertOk()->json('data');
}

function newStudent(Tenant $tenant, string $token): TestResponse
{
    return api($tenant, $token)->postJson('/api/v1/students', [
        'first_name' => 'أمين', 'last_name' => 'بن علي', 'first_name_latin' => 'Amine', 'last_name_latin' => 'Benali',
        'gender' => 'male', 'birth_date' => '2012-05-14', 'wilaya_code' => 16, 'phone' => '0661234567',
    ]);
}

it('ships three plans and lets the platform manage them', function (): void {
    $token = platformSession();

    $plans = api(null, $token)->getJson('/api/v1/platform/plans')->assertOk();
    expect(collect($plans->json('data'))->pluck('code')->all())->toBe(['starter', 'standard', 'premium'])
        ->and($plans->json('data.0.max_students'))->toBe(100)->and($plans->json('data.0.max_users'))->toBe(5)
        ->and($plans->json('data.2.max_students'))->toBeNull();

    api(null, $token)->postJson('/api/v1/platform/plans', ['code' => 'Bad Code', 'name' => 'X', 'price_monthly' => 1])->assertUnprocessable()->assertJsonValidationErrors('code');
    api(null, $token)->postJson('/api/v1/platform/plans', ['code' => 'starter', 'name' => 'X', 'price_monthly' => 1])->assertUnprocessable()->assertJsonValidationErrors('code');
    api(null, $token)->postJson('/api/v1/platform/plans', ['code' => 'tiny', 'name' => 'Tiny', 'price_monthly' => 150000, 'max_students' => 10, 'max_users' => 2])
        ->assertCreated()->assertJsonPath('data.max_students', 10);

    $id = Plan::query()->where('code', 'tiny')->value('id');
    api(null, $token)->patchJson("/api/v1/platform/plans/{$id}", ['price_monthly' => 200000, 'max_students' => null])->assertOk()
        ->assertJsonPath('data.price_monthly', 200000)->assertJsonPath('data.max_students', null);
    api(null, $token)->patchJson("/api/v1/platform/plans/{$id}", ['code' => 'other'])->assertUnprocessable();

    [$tenant, $owner] = ownerSession();
    expect(api(null, $owner)->getJson('/api/v1/platform/plans')->status())->toBeIn([401, 403]);
});

it('starts a new institution on a trial of the standard plan and shows it to the owner', function (): void {
    [$tenant, $owner] = ownerSession();

    $state = api($tenant, $owner)->getJson('/api/v1/subscription')->assertOk()
        ->assertJsonPath('data.plan.code', 'standard')->assertJsonPath('data.status', 'trial')->assertJsonPath('data.access_level', 'full')
        ->assertJsonPath('data.usage.students', 0)->assertJsonPath('data.usage.users', 1)->assertJsonPath('data.paid_until', null)
        ->assertJsonPath('data.days_left', 30)->json('data');
    expect($state['grace_ends_on'])->toBe(now()->addDays(37)->toDateString());

    api($tenant, $owner)->getJson('/api/v1/auth/me')->assertJsonPath('subscription.plan.code', 'standard');
    api($tenant, roleToken($tenant, 'teacher'))->getJson('/api/v1/subscription')->assertForbidden();
});

it('records subscription payments that start after the current coverage and are idempotent', function (): void {
    [$tenant] = ownerSession();
    $trialEnd = now()->addDays(30);

    $first = payFor($tenant, ['months' => 3, 'reference' => 'CCP-1'])->assertCreated()
        ->assertJsonPath('data.amount', 900000)->assertJsonPath('data.plan.code', 'starter')->assertJsonPath('replayed', false);
    $start = $trialEnd->copy()->addDay();
    $end = $start->copy()->addMonthsNoOverflow(3)->subDay();
    expect($first->json('data.period_start'))->toBe($start->toDateString())->and($first->json('data.period_end'))->toBe($end->toDateString())
        ->and($first->json('subscription.paid_until'))->toBe($end->toDateString())->and($first->json('subscription.plan.code'))->toBe('starter')
        ->and($first->json('subscription.status'))->toBe('trial'); // paid time has not started yet

    $second = payFor($tenant, ['plan' => 'standard', 'amount' => 700000, 'paid_on' => now()->toDateString()])->assertCreated()->assertJsonPath('data.amount', 700000);
    expect($second->json('data.period_start'))->toBe($end->copy()->addDay()->toDateString());

    $key = ['idempotency_key' => 'receipt-42'];
    payFor($tenant, $key)->assertCreated()->assertJsonPath('replayed', false);
    payFor($tenant, $key)->assertOk()->assertJsonPath('replayed', true);
    $history = api(null, platformSession())->getJson("/api/v1/platform/tenants/{$tenant->id}/subscriptions")->assertOk();
    expect($history->json('data'))->toHaveCount(3)->and($history->json('data.0.period_end') >= $history->json('data.2.period_end'))->toBeTrue();

    payFor($tenant, ['months' => 0])->assertUnprocessable()->assertJsonValidationErrors('months');
    payFor($tenant, ['months' => 25])->assertUnprocessable();
    payFor($tenant, ['method' => 'bitcoin'])->assertUnprocessable()->assertJsonValidationErrors('method');
    payFor($tenant, ['plan' => 'nope'])->assertUnprocessable()->assertJsonValidationErrors('plan');
    payFor($tenant, ['paid_on' => now()->addDay()->toDateString()])->assertUnprocessable()->assertJsonValidationErrors('paid_on');

    $premium = Plan::query()->where('code', 'premium')->value('id');
    api(null, platformSession())->patchJson("/api/v1/platform/plans/{$premium}", ['is_active' => false])->assertOk();
    payFor($tenant, ['plan' => 'premium'])->assertUnprocessable();

    $tenant->update(['status' => 'suspended']);
    payFor($tenant)->assertUnprocessable();
});

it('refuses a plan smaller than the current usage unless forced', function (): void {
    [$tenant] = ownerSession();
    Plan::query()->create(['code' => 'tiny', 'name' => 'Tiny', 'price_monthly' => 100000, 'max_students' => 1, 'max_users' => 5]);
    makeStudent($tenant, 'A', 'A');
    makeStudent($tenant, 'B', 'B');

    payFor($tenant, ['plan' => 'tiny'])->assertUnprocessable();
    payFor($tenant, ['plan' => 'tiny', 'force' => true])->assertCreated();
    expect(coverage($tenant)['plan']['code'])->toBe('tiny');
});

it('enforces the plan quotas on students and staff, and frees the slot when one is deactivated', function (): void {
    [$tenant, $owner] = ownerSession();
    Plan::query()->create(['code' => 'micro', 'name' => 'Micro', 'price_monthly' => 100000, 'max_students' => 2, 'max_users' => 2]);
    $tenant->update(['plan' => 'micro']);

    $one = newStudent($tenant, $owner)->assertCreated()->json('data.id');
    newStudent($tenant, $owner)->assertCreated();
    newStudent($tenant, $owner)->assertStatus(402)->assertJsonPath('detail', fn ($d) => str_contains($d, 'allows 2 students'));

    api($tenant, $owner)->patchJson("/api/v1/students/{$one}", ['is_active' => false])->assertOk();
    $third = newStudent($tenant, $owner)->assertCreated()->json('data.id');
    api($tenant, $owner)->patchJson("/api/v1/students/{$one}", ['is_active' => true])->assertStatus(402);   // reactivating needs a free slot too
    api($tenant, $owner)->patchJson("/api/v1/students/{$third}", ['first_name' => 'Renamed'])->assertOk();  // editing never does

    $newUser = fn (string $email) => api($tenant, $owner)->postJson('/api/v1/users', [
        'name' => 'Staff', 'email' => $email, 'password' => TEST_PASSWORD, 'roles' => ['teacher'],
    ]);
    $staff = $newUser('one@school-a.test')->assertCreated()->json('data.id');
    $newUser('two@school-a.test')->assertStatus(402);
    api($tenant, $owner)->patchJson("/api/v1/users/{$staff}", ['is_active' => false])->assertOk();
    $newUser('two@school-a.test')->assertCreated();

    // parent and student accounts are free, at the limit and when reactivated
    $guardian = inTenant($tenant, fn () => Guardian::query()->create(['first_name' => 'Nadia', 'last_name' => 'B', 'phone' => '0555123456']));
    api($tenant, $owner)->postJson("/api/v1/portal-accounts/guardians/{$guardian->id}", ['email' => 'nadia@family.test', 'password' => TEST_PASSWORD])->assertCreated();
    api($tenant, $owner)->patchJson("/api/v1/portal-accounts/guardians/{$guardian->id}", ['is_active' => false])->assertOk();
    api($tenant, $owner)->patchJson("/api/v1/portal-accounts/guardians/{$guardian->id}", ['is_active' => true])->assertOk();
    expect(coverage($tenant)['usage'])->toBe(['students' => 2, 'users' => 2]);

    // premium has no limit, and a tenant without a known plan is unlimited
    $tenant->update(['plan' => 'premium']);
    newStudent($tenant, $owner)->assertCreated();
    $tenant->update(['plan' => null]);
    newStudent($tenant, $owner)->assertCreated();
});

it('turns read-only after the grace period and recovers when the payment is recorded', function (): void {
    [$tenant] = ownerSession();
    $write = fn (string $token, string $name) => api($tenant, $token)->postJson('/api/v1/rooms', ['name' => $name, 'capacity' => 10]);

    // day 33: the trial ended 3 days ago, still inside the 7-day grace period
    test()->travel(33)->days();
    $token = tokenFor($tenant, "owner@school-a.test");
    api($tenant, $token)->getJson('/api/v1/subscription')->assertJsonPath('data.access_level', 'grace')->assertJsonPath('data.days_left', -3);
    $write($token, 'R1')->assertCreated();
    $this->artisan('subscriptions:sync')->expectsOutput('Relabelled 1 tenant(s).')->assertSuccessful();
    expect($tenant->refresh()->status->value)->toBe('past_due');
    $this->artisan('subscriptions:sync')->expectsOutput('Relabelled 0 tenant(s).')->assertSuccessful();

    // day 40: grace is over
    test()->travel(7)->days();
    $token = tokenFor($tenant, "owner@school-a.test");                                              // signing in still works
    api($tenant, $token)->getJson('/api/v1/rooms')->assertOk()->assertJsonCount(1, 'data');   // so does reading
    api($tenant, $token)->getJson('/api/v1/subscription')->assertJsonPath('data.access_level', 'read_only');
    $write($token, 'R2')->assertStatus(402)->assertJsonPath('detail', fn ($d) => str_contains($d, 'read-only'));
    api($tenant, $token)->postJson('/api/v1/inbox/read-all')->assertOk();
    api($tenant, $token)->postJson('/api/v1/auth/logout')->assertNoContent();
    $this->artisan('subscriptions:sync')->expectsOutput('Relabelled 1 tenant(s).')->assertSuccessful();
    expect($tenant->refresh()->status->value)->toBe('read_only');

    // the payment starts today because the coverage had lapsed
    $paid = payFor($tenant, ['months' => 1])->assertCreated();
    expect($paid->json('data.period_start'))->toBe(now()->toDateString())->and($paid->json('subscription.status'))->toBe('active')
        ->and($paid->json('subscription.access_level'))->toBe('full');
    $write(tokenFor($tenant, "owner@school-a.test"), 'R2')->assertCreated();

    // suspension is still a full block
    $tenant->update(['status' => 'suspended']);
    api($tenant)->postJson('/api/v1/auth/login', ['email' => "owner@school-a.test", 'password' => TEST_PASSWORD])->assertForbidden();
});

it('voids a recorded period and recomputes the coverage from what remains', function (): void {
    [$tenant] = ownerSession();
    $a = payFor($tenant, ['months' => 2])->assertCreated()->json('data');
    $b = payFor($tenant, ['months' => 1, 'plan' => 'standard'])->assertCreated()->json('data');
    $platform = fn () => api(null, platformSession());

    expect(coverage($tenant)['plan']['code'])->toBe('standard');

    $platform()->postJson("/api/v1/platform/tenants/{$tenant->id}/subscriptions/{$b['id']}/void", ['reason' => 'x'])->assertUnprocessable();
    $platform()->postJson("/api/v1/platform/tenants/{$tenant->id}/subscriptions/{$b['id']}/void", ['reason' => 'Cheque bounced'])->assertOk()
        ->assertJsonPath('data.status', 'void')->assertJsonPath('subscription.paid_until', $a['period_end'])->assertJsonPath('subscription.plan.code', 'starter');
    $platform()->postJson("/api/v1/platform/tenants/{$tenant->id}/subscriptions/{$b['id']}/void", ['reason' => 'Again please'])->assertUnprocessable();
    $platform()->postJson("/api/v1/platform/tenants/{$tenant->id}/subscriptions/9999/void", ['reason' => 'Nothing here'])->assertNotFound();

    $platform()->postJson("/api/v1/platform/tenants/{$tenant->id}/subscriptions/{$a['id']}/void", ['reason' => 'Paid twice'])->assertOk()
        ->assertJsonPath('subscription.paid_until', null)->assertJsonPath('subscription.status', 'trial');
    expect(coverage($tenant)['coverage_ends_on'])->toBe(now()->addDays(30)->toDateString());

    // recorded periods are append-only
    expect(fn () => TenantSubscription::query()->find($a['id'])->update(['amount' => 1]))->toThrow(LogicException::class)
        ->and(fn () => TenantSubscription::query()->find($a['id'])->delete())->toThrow(LogicException::class);

    // another tenant's periods are out of reach
    [$other] = ownerSession('school-b');
    $platform()->postJson("/api/v1/platform/tenants/{$other->id}/subscriptions/{$a['id']}/void", ['reason' => 'Not yours'])->assertNotFound();
});
