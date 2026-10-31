<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Message;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;

/*
| Systematic checks that walk EVERY registered API route, so a route added later is covered without writing a test for it.
*/

const PUBLIC_API = ['api/v1/auth/login', 'api/v1/auth/refresh', 'api/v1/auth/two-factor/challenge',
    'api/v1/platform/auth/login', 'api/v1/platform/auth/refresh', 'api/v1/platform/auth/two-factor/challenge'];

/** @return list<array{0: string, 1: string, 2: LaravelRoute}> method, concrete uri, route */
function allApiRoutes(): array
{
    $out = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/v1/')) {
            continue;
        }

        $uri = '/'.$route->uri();

        foreach ($route->parameterNames() as $name) {
            $where = $route->wheres[$name] ?? null;
            $value = is_string($where) && ! str_contains($where, '[') ? explode('|', $where)[0] : '1';   // whereIn('type', [...]) -> first value
            $uri = str_replace(['{'.$name.'}', '{'.$name.'?}'], $value, $uri);
        }

        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $out[] = [$method, $uri, $route];
        }
    }

    return $out;
}

function isTenantRoute(LaravelRoute $route): bool
{
    return in_array('tenant', $route->gatherMiddleware(), true);
}

/** Routes any signed-in user may call about themselves: no permission by design. */
function isSelfService(string $uri): bool
{
    foreach (['/api/v1/auth/', '/api/v1/inbox'] as $prefix) {
        if (str_starts_with($uri, $prefix)) {
            return true;
        }
    }

    return false;
}

function notSuccessNorCrash(int $status): bool
{
    return $status < 200 || ($status >= 300 && $status < 500);
}

beforeEach(function (): void {
    test()->withoutMiddleware(ThrottleRequests::class);   // hundreds of calls from one address
});

it('refuses every API route to an anonymous caller, except the sign-in ones', function (): void {
    [$tenant] = provisionTenant();
    $checked = 0;

    foreach (allApiRoutes() as [$method, $uri, $route]) {
        if (in_array($route->uri(), PUBLIC_API, true)) {
            continue;
        }

        $status = api(isTenantRoute($route) ? $tenant : null)->json($method, $uri)->status();
        $this->assertSame(401, $status, "{$method} {$uri} answered {$status} to an anonymous caller");
        $checked++;
    }

    expect($checked)->toBeGreaterThan(100);
});

it('gives an account without permissions access to nothing but itself', function (): void {
    [$tenant] = provisionTenant();
    $token = roleToken($tenant, 'student');   // a portal role: no permission, no student profile
    $checked = 0;

    foreach (allApiRoutes() as [$method, $uri, $route]) {
        if (! isTenantRoute($route) || in_array($route->uri(), PUBLIC_API, true) || isSelfService($uri)) {
            continue;
        }

        $status = api($tenant, $token)->json($method, $uri)->status();
        $this->assertContains($status, [403, 404], "{$method} {$uri} answered {$status} to an account without permissions");
        $checked++;
    }

    expect($checked)->toBeGreaterThan(100);
});

it('keeps platform and tenant sides apart in both directions', function (): void {
    [$tenant, $ownerToken] = ownerSession();
    $platform = platformToken();

    foreach (allApiRoutes() as [$method, $uri, $route]) {
        if (in_array($route->uri(), PUBLIC_API, true)) {
            continue;
        }

        $status = isTenantRoute($route)
            ? api($tenant, $platform)->json($method, $uri)->status()        // a platform admin on a tenant route
            : api(null, $ownerToken)->json($method, $uri)->status();         // a tenant owner on a platform route

        $this->assertContains($status, [401, 403], "{$method} {$uri} answered {$status} across the platform/tenant border");
    }
});

it('never lets one institution reach, change or delete the rows of another by id', function (): void {
    $this->seed(DemoSeeder::class);                 // institutions with rows at ids 1, 2, 3...
    [$tenant, $ownerToken] = ownerSession('school-a');   // created later: its own ids are all higher
    $checked = 0;

    foreach (allApiRoutes() as [$method, $uri, $route]) {
        if (! isTenantRoute($route) || in_array($route->uri(), PUBLIC_API, true) || isSelfService($uri) || $route->parameterNames() === [] || str_contains($uri, '/portal/')) {
            continue;
        }

        $status = api($tenant, $ownerToken)->json($method, $uri)->status();
        $this->assertTrue(notSuccessNorCrash($status), "{$method} {$uri} answered {$status}: another institution's row (id 1) was reachable, or the route crashed");
        $checked++;
    }

    expect($checked)->toBeGreaterThan(80);
});

it('puts every tenant-owned model behind the tenant scope', function (): void {
    $global = ['AuditLog', 'ImpersonationSession', 'Plan', 'PlatformAdmin', 'RefreshToken', 'Tenant', 'TenantSubscription'];

    foreach (glob(app_path('Models/*.php')) as $file) {
        $name = basename($file, '.php');

        if (! in_array($name, $global, true)) {
            $this->assertStringContainsString('BelongsToTenant', (string) file_get_contents($file), "{$name} holds institution data but is not behind the tenant scope");
        }
    }
});

it('ignores fields a caller has no right to set', function (): void {
    [$tenant, $token] = ownerSession();
    [$other] = provisionTenant('school-b');

    $id = api($tenant, $token)->postJson('/api/v1/users', [
        'name' => 'Sneaky', 'email' => 'sneaky@school-a.test', 'password' => 'An0ther-Str0ng-Pass!', 'roles' => ['secretary'],
        'tenant_id' => $other->id, 'is_admin' => true, 'two_factor_confirmed_at' => now()->toDateTimeString(),
    ])->assertCreated()->assertJsonMissingPath('data.password')->json('data.id');

    $user = inTenant($tenant, fn () => User::query()->findOrFail($id));
    expect((int) $user->tenant_id)->toBe($tenant->id)->and($user->two_factor_confirmed_at)->toBeNull();

    $student = makeStudent($tenant);
    api($tenant, $token)->patchJson("/api/v1/students/{$student->id}", ['first_name' => 'Changed', 'registration_number' => 'HACKED', 'tenant_id' => $other->id])->assertOk();

    $fresh = inTenant($tenant, fn () => $student->fresh());
    expect($fresh->first_name)->toBe('Changed')->and($fresh->registration_number)->not->toBe('HACKED')
        ->and((int) $fresh->tenant_id)->toBe($tenant->id);
});

it('never shows a password or a two-factor secret in any answer or audit entry', function (): void {
    [$tenant, $token] = ownerSession();
    $roles = api($tenant, $token)->getJson('/api/v1/users')->assertOk()->getContent();

    foreach (['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'] as $secret) {
        expect($roles)->not->toContain($secret);
    }

    $audit = json_encode(AuditLog::query()->get(['old_values', 'new_values'])->toArray());
    expect($audit)->not->toContain('Str0ng-Passw0rd')->and($audit)->not->toContain('two_factor_secret');
});

it('keeps a support session from texting the parents', function (): void {
    config(['saas.impersonation.require_two_factor' => false]);
    [$tenant] = provisionTenant();
    $platform = platformToken();

    $support = api(null, $platform)->postJson("/api/v1/platform/tenants/{$tenant->id}/impersonate", ['reason' => 'Checking the message log', 'write' => true])->assertCreated()->json('token');

    api($tenant, $support)->postJson('/api/v1/messages', ['channel' => 'sms', 'body' => 'x', 'audience' => ['type' => 'guardians', 'all' => true]])->assertForbidden();
    api($tenant, $support)->postJson('/api/v1/alerts/absences/run')->assertForbidden();
    api($tenant, $support)->getJson('/api/v1/messages')->assertOk();
});

it('limits the external messages an institution can queue by hand per day', function (): void {
    [$tenant, $token] = ownerSession();
    config(['messaging.daily_limit' => 2]);

    $group = makeGroup($tenant, makeCurrentYear($tenant));
    foreach (['A', 'B', 'C'] as $i => $name) {
        $student = makeStudent($tenant, $name);
        enrollNow($tenant, $student, $group);
        inTenant($tenant, fn () => $student->guardians()->create(
            ['first_name' => 'P'.$name, 'last_name' => 'X', 'phone' => '05500000'.(10 + $i)],
            ['tenant_id' => $tenant->id, 'relationship' => 'father', 'is_primary' => true, 'is_financial_responsible' => true],
        ));
    }

    $send = fn () => api($tenant, $token)->postJson('/api/v1/messages', ['channel' => 'sms', 'body' => 'Hello {guardian_name}', 'audience' => ['type' => 'guardians', 'group_id' => $group->id]]);

    $send()->assertStatus(429);   // 3 recipients against a limit of 2
    expect(inTenant($tenant, fn () => Message::query()->count()))->toBe(0);

    config(['messaging.daily_limit' => 10]);
    $send()->assertCreated();
});

it('throttles per session, so one client cannot lock out its colleagues', function (): void {
    test()->withMiddleware(ThrottleRequests::class);
    [$tenant, $first] = ownerSession();
    $second = roleToken($tenant, 'manager');

    for ($i = 0; $i < 120; $i++) {
        api($tenant, $first)->getJson('/api/v1/auth/me')->assertOk();
    }

    api($tenant, $first)->getJson('/api/v1/auth/me')->assertStatus(429);
    api($tenant, $second)->getJson('/api/v1/auth/me')->assertOk();
});
