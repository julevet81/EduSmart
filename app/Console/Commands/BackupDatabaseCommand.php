<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Backup\BackupService;
use Illuminate\Console\Command;
use Throwable;

final class BackupDatabaseCommand extends Command
{
    protected $signature = 'db:backup {--no-prune : Keep old backups}';

    protected $description = 'Back up the whole database (gzip, encrypted when BACKUP_PASSPHRASE is set) and remove backups past their retention.';

    public function handle(BackupService $backups): int
    {
        try {
            $file = $backups->create();
        } catch (Throwable $e) {
            $this->error('Backup failed: '.$e->getMessage());
            report($e);

            return self::FAILURE;
        }

        $this->info(sprintf('Backup written: %s (%s KB)', $file, number_format(filesize($file) / 1024, 1)));

        if (! $this->option('no-prune')) {
            $this->line('Old backups removed: '.$backups->prune());
        }

        return self::SUCCESS;
    }
}
