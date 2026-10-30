<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
| Everything the platform does on its own. One cron entry drives it all (see docs/OPERATIONS.md):
|   * * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
| Times follow APP_TIMEZONE (Africa/Algiers). withoutOverlapping() + onOneServer() keep two workers from doing the same job.
*/

// Sends the queued SMS / WhatsApp / email messages of every institution.
Schedule::command('messages:dispatch')->everyMinute()->withoutOverlapping(10)->onOneServer();

// Absence alerts and overdue-payment reminders (idempotent per week).
Schedule::command('messages:alerts')->dailyAt('08:00')->withoutOverlapping()->onOneServer();

// Re-labels trial / active / past_due / read_only. Access itself is computed from dates, so a missed run is harmless.
Schedule::command('subscriptions:sync')->dailyAt('00:10')->onOneServer();

// Removes refresh tokens that can no longer be used.
Schedule::command('auth:prune-refresh-tokens')->dailyAt('03:30')->onOneServer();

// Database backup, only when BACKUP_ENABLED=true.
Schedule::command('db:backup')->dailyAt((string) config('backup.time', '02:30'))->when(fn (): bool => (bool) config('backup.enabled'))
    ->withoutOverlapping()->onOneServer();
