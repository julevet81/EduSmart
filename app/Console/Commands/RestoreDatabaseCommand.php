<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Backup\BackupService;
use Illuminate\Console\Command;
use Throwable;

final class RestoreDatabaseCommand extends Command
{
    protected $signature = 'db:restore {file : Path of a backup made by db:backup} {--force : Required in production}';

    protected $description = 'Restore the database from a backup. This REPLACES the current data.';

    public function handle(BackupService $backups): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('Refusing to restore in production without --force.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm('This replaces the current data of the "'.config('database.default').'" database. Continue?')) {
            return self::FAILURE;
        }

        try {
            $backups->restore((string) $this->argument('file'));
        } catch (Throwable $e) {
            $this->error('Restore failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Database restored. Run "php artisan migrate" if the backup is older than the code.');

        return self::SUCCESS;
    }
}
