<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/** Production readiness checklist: run it after every deployment. Exit code 1 when something that must be fixed is found. */
final class PreflightCommand extends Command
{
    protected $signature = 'app:preflight {--strict : Treat warnings as failures}';

    protected $description = 'Check that the configuration is safe for production (debug, keys, https, CORS, database, backups, messaging).';

    public function handle(): int
    {
        $rows = collect($this->checks())->map(fn (array $c): array => [$c[0], $c[1], $c[2]]);
        $this->table(['Check', 'Result', 'Detail'], $rows->all());

        $failed = $rows->where(1, 'FAIL')->count();
        $warned = $rows->where(1, 'warn')->count();
        $this->line("{$failed} to fix, {$warned} warning(s).");

        return $failed > 0 || ($warned > 0 && $this->option('strict')) ? self::FAILURE : self::SUCCESS;
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    public function checks(): array
    {
        $default = (string) config('database.default');
        $db = (array) config("database.connections.{$default}");
        $origins = (array) config('cors.allowed_origins');
        $drivers = (array) config('messaging.drivers');

        return [
            $this->check('Environment is production', app()->environment('production'), 'warn', 'APP_ENV='.app()->environment()),
            $this->check('APP_DEBUG is off', ! config('app.debug'), 'FAIL', 'Debug pages leak code and secrets.'),
            $this->check('APP_KEY is set', (string) config('app.key') !== '', 'FAIL', 'Run: php artisan key:generate'),
            $this->check('APP_URL uses https', str_starts_with((string) config('app.url'), 'https://'), 'FAIL', (string) config('app.url')),
            $this->check('Database is not SQLite', ! in_array($db['driver'] ?? 'sqlite', ['sqlite'], true), 'FAIL', "driver={$default}"),
            $this->check('Database has a password', ($db['driver'] ?? '') === 'sqlite' || (string) ($db['password'] ?? '') !== '', 'FAIL', 'DB_PASSWORD is empty.'),
            $this->check('CORS has no wildcard', ! in_array('*', $origins, true), 'FAIL', 'List the front-end origins explicitly.'),
            $this->check('CORS origins are configured', $origins !== [] || (array) config('cors.allowed_origins_patterns') !== [], 'warn', 'CORS_ALLOWED_ORIGINS is empty: browser front-ends are refused.'),
            $this->check('Cache store is shared', ! in_array(config('cache.default'), ['array'], true), 'FAIL', 'Rate limits, 2FA challenges and tenant cache need a real store.'),
            $this->check('Queue is not sync', config('queue.default') !== 'sync', 'warn', 'QUEUE_CONNECTION=sync.'),
            $this->check('Access tokens are short', (int) config('saas.tokens.tenant_ttl_minutes') <= 240 && (int) config('saas.tokens.platform_ttl_minutes') <= 60, 'warn', 'TENANT_TOKEN_TTL_MINUTES / PLATFORM_TOKEN_TTL_MINUTES are long: the refresh token renews them.'),
            $this->check('Support access requires 2FA', (bool) config('saas.impersonation.require_two_factor'), 'FAIL', 'IMPERSONATION_REQUIRE_2FA=false.'),
            $this->check('Messages are really sent', ! in_array('log', $drivers, true) && ! in_array('null', $drivers, true), 'warn', 'A messaging channel still uses the log/null driver.'),
            $this->check('Backups are enabled', (bool) config('backup.enabled'), 'FAIL', 'BACKUP_ENABLED=false.'),
            $this->check('Backups are encrypted', (string) config('backup.passphrase') !== '', 'warn', 'BACKUP_PASSPHRASE is empty.'),
        ];
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function check(string $name, bool $ok, string $levelIfBad, string $detail): array
    {
        return [$name, $ok ? 'ok' : $levelIfBad, $ok ? '' : $detail];
    }
}
