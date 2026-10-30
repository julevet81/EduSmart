<?php

declare(strict_types=1);

use App\Support\Backup\BackupCrypto;
use App\Support\Backup\BackupService;
use App\Support\Docs\OpenApiBuilder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

/** A throw-away SQLite file registered as its own connection, so the test database is never touched. @return array{0: string, 1: string} dir, database file */
function backupWorld(array $config = []): array
{
    $dir = sys_get_temp_dir().'/edubk-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);
    $db = $dir.'/app.sqlite';
    file_put_contents($db, str_repeat('school data ', 20000));

    config($config + ['database.connections.filedb' => ['driver' => 'sqlite', 'database' => $db, 'prefix' => ''],
        'backup.path' => $dir.'/backups', 'backup.passphrase' => null, 'backup.keep_days' => 14, 'backup.keep_min' => 2]);

    return [$dir, $db];
}

// ---- backups ---------------------------------------------------------------------------------------------------

it('backs the database up, checksums it, and restores it byte for byte', function (): void {
    [, $db] = backupWorld();
    $original = file_get_contents($db);

    $file = app(BackupService::class)->create('filedb');

    expect($file)->toEndWith('.sqlite.gz')->and(is_file($file.'.sha256'))->toBeTrue()
        ->and(filesize($file))->toBeLessThan(strlen($original) / 10)
        ->and(gzdecode((string) file_get_contents($file)))->toBe($original);

    file_put_contents($db, 'the database was lost');
    app(BackupService::class)->restore($file, 'filedb');

    expect(file_get_contents($db))->toBe($original)->and(file_get_contents($db.'.pre-restore'))->toBe('the database was lost');
});

it('refuses a backup whose checksum no longer matches', function (): void {
    backupWorld();
    $file = app(BackupService::class)->create('filedb');
    file_put_contents($file, 'tampered', FILE_APPEND);

    expect(fn () => app(BackupService::class)->restore($file, 'filedb'))->toThrow(RuntimeException::class, 'checksum');
});

it('encrypts backups when a passphrase is set and refuses a wrong one', function (): void {
    [, $db] = backupWorld(['backup.passphrase' => 'correct horse battery staple']);
    $original = file_get_contents($db);

    $file = app(BackupService::class)->create('filedb');
    expect($file)->toEndWith('.enc')->and(file_get_contents($file))->toStartWith('EDUBK1')->and(file_get_contents($file))->not->toContain('school data');

    file_put_contents($db, 'lost');
    config(['backup.passphrase' => 'a different passphrase']);
    expect(fn () => app(BackupService::class)->restore($file, 'filedb'))->toThrow(RuntimeException::class);
    expect(file_get_contents($db))->toBe('lost');

    config(['backup.passphrase' => 'correct horse battery staple']);
    app(BackupService::class)->restore($file, 'filedb');
    expect(file_get_contents($db))->toBe($original);
});

it('detects an altered or cut encrypted stream', function (): void {
    $dir = sys_get_temp_dir().'/edubk-'.bin2hex(random_bytes(6));
    mkdir($dir);
    file_put_contents("{$dir}/plain", random_bytes(200_000));   // several 64 KB chunks
    BackupCrypto::encrypt("{$dir}/plain", "{$dir}/enc", 'secret');

    BackupCrypto::decrypt("{$dir}/enc", "{$dir}/back", 'secret');
    expect(file_get_contents("{$dir}/back"))->toBe(file_get_contents("{$dir}/plain"));

    $bytes = file_get_contents("{$dir}/enc");
    file_put_contents("{$dir}/flipped", substr_replace($bytes, chr(ord($bytes[100]) ^ 1), 100, 1));
    file_put_contents("{$dir}/cut", substr($bytes, 0, 70_000));
    // exactly the first chunk, intact but not final: a silent truncation must still be caught
    $firstChunk = 6 + 16 + 24 + 4 + unpack('N', substr($bytes, 46, 4))[1];
    file_put_contents("{$dir}/short", substr($bytes, 0, $firstChunk));

    foreach (['flipped', 'cut', 'short'] as $name) {
        expect(fn () => BackupCrypto::decrypt("{$dir}/{$name}", "{$dir}/out-{$name}", 'secret'))->toThrow(RuntimeException::class);
        expect(file_exists("{$dir}/out-{$name}"))->toBeFalse();
    }
});

it('keeps the newest backups and removes the ones past retention', function (): void {
    backupWorld();
    $dir = config('backup.path');
    mkdir($dir, 0700, true);

    foreach ([30, 25, 20, 1] as $days) {
        touch("{$dir}/b-{$days}.sql.gz", time() - $days * 86400);
        touch("{$dir}/b-{$days}.sql.gz.sha256", time() - $days * 86400);
    }

    expect(app(BackupService::class)->prune())->toBe(2)
        ->and(array_map('basename', app(BackupService::class)->files()))->toBe(['b-1.sql.gz', 'b-20.sql.gz'])
        ->and(file_exists("{$dir}/b-30.sql.gz.sha256"))->toBeFalse();
});

it('cannot back up an in-memory database', function (): void {
    config(['database.connections.memdb' => ['driver' => 'sqlite', 'database' => ':memory:'], 'backup.path' => sys_get_temp_dir().'/edubk-'.bin2hex(random_bytes(4))]);

    expect(fn () => app(BackupService::class)->create('memdb'))->toThrow(RuntimeException::class, 'in-memory');
});

// ---- scheduler -------------------------------------------------------------------------------------------------

it('schedules every recurring job', function (): void {
    Artisan::call('schedule:list');
    $output = Artisan::output();

    foreach (['messages:dispatch', 'messages:alerts', 'subscriptions:sync', 'auth:prune-refresh-tokens', 'db:backup'] as $command) {
        expect($output)->toContain($command);
    }
});

// ---- production checks -----------------------------------------------------------------------------------------

it('passes the production checklist when the configuration is sound and fails it when it is not', function (): void {
    // Never change database.default here: the test transaction must roll back on the connection it started on.
    $name = (string) config('database.default');
    $original = config("database.connections.{$name}");

    try {
        config([
            'app.debug' => false, 'app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.url' => 'https://api.myapp.dz',
            "database.connections.{$name}" => ['driver' => 'mysql', 'password' => 'secret'],
            'cors.allowed_origins' => ['https://app.myapp.dz'], 'cache.default' => 'database', 'queue.default' => 'database',
            'saas.impersonation.require_two_factor' => true, 'backup.enabled' => true,
        ]);

        expect(Artisan::call('app:preflight'))->toBe(0);

        // warnings (not production here, messages still on the log driver, backups unencrypted) fail only in strict mode
        expect(Artisan::call('app:preflight', ['--strict' => true]))->toBe(1);

        config(['app.debug' => true, 'cors.allowed_origins' => ['*']]);
        $code = Artisan::call('app:preflight');
        $output = Artisan::output();
        expect($code)->toBe(1)->and($output)->toContain('APP_DEBUG is off')->and($output)->toContain('CORS has no wildcard');
    } finally {
        config(["database.connections.{$name}" => $original]);
    }
});

// ---- HTTP hardening --------------------------------------------------------------------------------------------

it('sends security headers on every API answer, and HSTS over https', function (): void {
    $plain = api()->getJson('/api/v1/platform/auth/me')->assertUnauthorized();

    $plain->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'DENY')->assertHeader('Referrer-Policy', 'no-referrer');
    expect($plain->headers->get('Cache-Control'))->toContain('no-store')->and($plain->headers->has('Strict-Transport-Security'))->toBeFalse();

    api()->getJson('https://localhost/api/v1/platform/auth/me')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

it('allows only the configured front-end origins', function (): void {
    config(['cors.allowed_origins' => ['https://app.myapp.dz']]);
    $preflight = fn (string $origin) => api()->call('OPTIONS', '/api/v1/auth/login', [], [], [], [
        'HTTP_ORIGIN' => $origin, 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST', 'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'x-tenant,authorization,content-type',
    ]);

    $preflight('https://app.myapp.dz')->assertHeader('Access-Control-Allow-Origin', 'https://app.myapp.dz');
    // with one configured origin the library always answers with that origin; a browser on any other origin refuses it
    expect($preflight('https://evil.test')->headers->get('Access-Control-Allow-Origin'))->not->toBe('https://evil.test');
});

// ---- OpenAPI ---------------------------------------------------------------------------------------------------

it('documents every route from the code itself', function (): void {
    $spec = app(OpenApiBuilder::class)->build();

    expect($spec['openapi'])->toBe('3.1.0');

    $routes = 0;
    foreach (Route::getRoutes()->getRoutes() as $route) {
        str_starts_with($route->uri(), 'api/v1/') && $routes += count(array_diff($route->methods(), ['HEAD']));
    }
    expect(array_sum(array_map('count', $spec['paths'])))->toBe($routes)->and($routes)->toBeGreaterThan(100);

    // public login: no security, tenant header, body taken from its FormRequest
    $login = $spec['paths']['/api/v1/auth/login']['post'];
    expect($login)->not->toHaveKey('security')
        ->and(collect($login['parameters'])->pluck('name'))->toContain('X-Tenant')
        ->and($login['requestBody']['content']['application/json']['schema']['required'])->toBe(['email', 'password']);

    // protected tenant route: bearer, permission and numeric id
    $students = $spec['paths']['/api/v1/students']['get'];
    expect($students['security'])->toBe([['bearerAuth' => []]])->and($students['x-permission'])->toBe(['students.view']);
    $one = $spec['paths']['/api/v1/students/{id}']['get'];
    expect(collect($one['parameters'])->firstWhere('name', 'id')['schema']['type'])->toBe('integer');

    // platform routes need no tenant header
    $impersonate = $spec['paths']['/api/v1/platform/tenants/{tenant}/impersonate']['post'];
    expect(collect($impersonate['parameters'])->pluck('name')->all())->toBe(['tenant'])->and($impersonate['tags'])->toBe(['platform/tenants']);
});

it('writes the document to a file', function (): void {
    $out = 'build/test-openapi-'.bin2hex(random_bytes(3)).'.json';

    expect(Artisan::call('api:openapi', ['--output' => $out]))->toBe(0);
    $json = json_decode((string) file_get_contents(base_path($out)), true, flags: JSON_THROW_ON_ERROR);
    expect($json['paths'])->toHaveKey('/api/v1/auth/login');

    unlink(base_path($out));
});
