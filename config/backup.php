<?php

declare(strict_types=1);

return [

    // The scheduler runs "db:backup" daily at "time" only when this is true.
    'enabled' => (bool) env('BACKUP_ENABLED', false),
    'time' => env('BACKUP_TIME', '02:30'),

    // Outside the web root. Copy it off the server too (another machine or bucket): a backup on the same disk is not a backup.
    'path' => env('BACKUP_PATH', storage_path('app/backups')),

    // Files older than keep_days are deleted, but the newest keep_min files are always kept.
    'keep_days' => (int) env('BACKUP_KEEP_DAYS', 14),
    'keep_min' => (int) env('BACKUP_KEEP_MIN', 3),

    // When set, every backup is encrypted (XChaCha20-Poly1305 stream, Argon2id key). Lose the passphrase and the backup is gone.
    'passphrase' => env('BACKUP_PASSPHRASE'),

    // Client binaries (put the full path here if they are not on PATH, typical on Windows).
    'mysqldump' => env('MYSQLDUMP_PATH', 'mysqldump'),
    'mysql' => env('MYSQL_CLIENT_PATH', 'mysql'),
    'pg_dump' => env('PG_DUMP_PATH', 'pg_dump'),
    'psql' => env('PSQL_PATH', 'psql'),

    'timeout' => (int) env('BACKUP_TIMEOUT', 3600),
];
