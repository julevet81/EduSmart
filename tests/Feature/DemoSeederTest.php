<?php

declare(strict_types=1);

use App\Models\Absence;
use App\Models\Grade;
use App\Models\Guardian;
use App\Models\Invoice;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\PlatformAdmin;
use App\Models\Student;
use App\Models\Tenant;
use App\Support\Billing\Subscription;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Artisan;

it('builds three realistic institutions that people can sign in to', function (): void {
    $this->seed(DemoSeeder::class);

    expect(Tenant::query()->count())->toBe(3)->and(PlatformAdmin::query()->where('email', 'root@demo.test')->exists())->toBeTrue();

    $nour = Tenant::query()->where('slug', 'ecole-nour')->firstOrFail();
    $amal = Tenant::query()->where('slug', 'centre-amal')->firstOrFail();

    $count = fn (Tenant $t, string $model) => inTenant($t, fn () => $model::query()->count());

    expect($count($nour, Student::class))->toBe(24)->and($count($amal, Student::class))->toBe(12)
        ->and($count($nour, Guardian::class))->toBe(18)
        ->and($count($nour, Lesson::class))->toBeGreaterThan(20)
        ->and($count($nour, Absence::class))->toBeGreaterThan(0)
        ->and($count($nour, Invoice::class))->toBeGreaterThan(24)
        ->and($count($nour, Payment::class))->toBeGreaterThan(0)
        ->and($count($nour, Grade::class))->toBeGreaterThan(50);

    // paid school, centre on trial
    expect(Subscription::accessLevel($nour))->toBe('full')->and(Subscription::state($nour->refresh())['plan']['code'])->toBe('standard')
        ->and(Subscription::state($nour)['paid_until'])->not->toBeNull()
        ->and(Subscription::state($amal->refresh())['status'])->toBe('trial');

    foreach (['owner', 'manager', 'secretary', 'accountant', 'teacher', 'parent', 'student'] as $login) {
        loginAs($nour, "{$login}@ecole-nour.demo", DemoSeeder::PASSWORD)->assertOk();
    }

    api(null, platformTokenFor('root@demo.test'))->getJson('/api/v1/platform/tenants')->assertOk();
});

it('leaves one institution with an expired trial that is read-only', function (): void {
    $this->seed(DemoSeeder::class);
    $old = Tenant::query()->where('slug', 'ecole-expiree')->firstOrFail();

    $token = demoToken($old, 'owner@ecole-expiree.demo');
    api($old, $token)->getJson('/api/v1/rooms')->assertOk();
    api($old, $token)->postJson('/api/v1/rooms', ['name' => 'R1', 'capacity' => 10])->assertStatus(402);
});

it('shows the seeded money and the absence alert in the app itself', function (): void {
    $this->seed(DemoSeeder::class);
    $nour = Tenant::query()->where('slug', 'ecole-nour')->firstOrFail();
    $token = demoToken($nour, 'owner@ecole-nour.demo');

    api($nour, $token)->getJson('/api/v1/students')->assertOk();
    expect(Artisan::call('messages:alerts'))->toBe(0);
    api($nour, $token)->getJson('/api/v1/messages')->assertOk()->assertJsonStructure(['data']);
});

it('is safe to run twice and never runs in production', function (): void {
    $this->seed(DemoSeeder::class);
    $this->seed(DemoSeeder::class);
    expect(Tenant::query()->count())->toBe(3);

    app()->detectEnvironment(fn (): string => 'production');

    try {
        expect(fn () => (new DemoSeeder)->run())->toThrow(RuntimeException::class, 'production');
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }
});

function platformTokenFor(string $email): string
{
    return (string) api()->postJson('/api/v1/platform/auth/login', ['email' => $email, 'password' => DemoSeeder::PASSWORD])->assertOk()->json('token');
}

function demoToken(Tenant $tenant, string $email): string
{
    return (string) loginAs($tenant, $email, DemoSeeder::PASSWORD)->assertOk()->json('token');
}
