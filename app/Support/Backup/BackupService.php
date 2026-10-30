<?php

declare(strict_types=1);

namespace App\Support\Backup;

use RuntimeException;
use Symfony\Component\Process\Process;

/** Full-database backups: mysql / pgsql through their dump tools, sqlite as a file copy. Always gzip, optionally encrypted. */
final class BackupService
{
    public function create(?string $connectionName = null): string
    {
        $connection = $this->connection($connectionName);
        $driver = (string) $connection['driver'];
        $dir = $this->directory();
        $name = sprintf('%s-%s.%s', preg_replace('/[^a-z0-9]+/i', '-', strtolower((string) config('app.name', 'app'))), now()->format('Ymd-His'), $driver === 'sqlite' ? 'sqlite.gz' : 'sql.gz');
        $plain = $dir.DIRECTORY_SEPARATOR.$name;
        $part = $plain.'.part';

        $gz = gzopen($part, 'wb9');
        $gz !== false || throw new RuntimeException('Cannot write the backup file.');

        try {
            match ($driver) {
                'sqlite' => $this->dumpSqlite($connection, $gz),
                'mysql', 'mariadb' => $this->dumpProcess($this->mysqlDump($connection), ['MYSQL_PWD' => (string) ($connection['password'] ?? '')], $gz),
                'pgsql' => $this->dumpProcess($this->pgDump($connection), ['PGPASSWORD' => (string) ($connection['password'] ?? '')], $gz),
                default => throw new RuntimeException("Backups are not supported for the {$driver} driver."),
            };
        } catch (\Throwable $e) {
            gzclose($gz);
            @unlink($part);
            throw $e;
        }

        gzclose($gz);

        $final = $plain;
        $passphrase = config('backup.passphrase');

        if (is_string($passphrase) && $passphrase !== '') {
            $final = $plain.'.enc';
            BackupCrypto::encrypt($part, $final.'.part', $passphrase);
            @unlink($part);
            rename($final.'.part', $final);
        } else {
            rename($part, $final);
        }

        @chmod($final, 0600);
        file_put_contents($final.'.sha256', hash_file('sha256', $final).'  '.basename($final).PHP_EOL);
        @chmod($final.'.sha256', 0600);

        return $final;
    }

    /** Deletes backups older than keep_days, always keeping the newest keep_min. @return int files removed */
    public function prune(): int
    {
        $files = $this->files();
        $limit = now()->subDays((int) config('backup.keep_days'))->getTimestamp();
        $removed = 0;

        foreach (array_slice($files, (int) config('backup.keep_min')) as $file) {
            if (filemtime($file) < $limit) {
                @unlink($file);
                @unlink($file.'.sha256');
                $removed++;
            }
        }

        return $removed;
    }

    /** @return list<string> backup files, newest first */
    public function files(): array
    {
        $dir = $this->directory();
        $found = array_values(array_map(
            static fn (string $name): string => $dir.DIRECTORY_SEPARATOR.$name,
            array_filter(scandir($dir) ?: [], static fn (string $name): bool => str_ends_with($name, '.gz') || str_ends_with($name, '.enc')),
        ));
        usort($found, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return $found;
    }

    public function restore(string $file, ?string $connectionName = null): void
    {
        is_file($file) || throw new RuntimeException("Backup not found: {$file}");
        $this->verify($file);

        $temp = null;
        $source = $file;

        if (str_ends_with($file, '.enc')) {
            $passphrase = config('backup.passphrase');
            is_string($passphrase) && $passphrase !== '' || throw new RuntimeException('This backup is encrypted: set BACKUP_PASSPHRASE.');
            $temp = $this->directory().DIRECTORY_SEPARATOR.'restore-'.bin2hex(random_bytes(6)).'.gz';
            BackupCrypto::decrypt($file, $temp, $passphrase);
            $source = $temp;
        }

        try {
            $connection = $this->connection($connectionName);

            match ((string) $connection['driver']) {
                'sqlite' => $this->restoreSqlite($connection, $source),
                'mysql', 'mariadb' => $this->restoreProcess($this->mysqlClient($connection), ['MYSQL_PWD' => (string) ($connection['password'] ?? '')], $source),
                'pgsql' => $this->restoreProcess($this->psqlClient($connection), ['PGPASSWORD' => (string) ($connection['password'] ?? '')], $source),
                default => throw new RuntimeException('Restore is not supported for this driver.'),
            };
        } finally {
            $temp !== null && @unlink($temp);
        }
    }

    private function verify(string $file): void
    {
        $sidecar = $file.'.sha256';

        if (is_file($sidecar)) {
            $expected = strtok((string) file_get_contents($sidecar), ' ');
            hash_equals((string) $expected, hash_file('sha256', $file)) || throw new RuntimeException('The checksum does not match: the backup was altered or damaged.');
        }
    }

    /** @return array<string, mixed> */
    private function connection(?string $name): array
    {
        $config = config('database.connections.'.($name ?? config('database.default')));
        is_array($config) || throw new RuntimeException('Unknown database connection.');

        return $config;
    }

    private function directory(): string
    {
        $dir = (string) config('backup.path');

        is_dir($dir) || mkdir($dir, 0700, true) || throw new RuntimeException("Cannot create {$dir}.");

        return $dir;
    }

    /** @param  array<string, mixed>  $c @param resource $gz */
    private function dumpSqlite(array $c, $gz): void
    {
        $path = (string) ($c['database'] ?? '');

        if ($path === '' || $path === ':memory:' || ! is_file($path)) {
            throw new RuntimeException('An in-memory or missing SQLite database cannot be backed up.');
        }

        $in = fopen($path, 'rb');

        while (! feof($in)) {
            gzwrite($gz, (string) fread($in, 65536));
        }

        fclose($in);
    }

    /** @param  array<string, mixed>  $c @return list<string> */
    private function mysqlDump(array $c): array
    {
        return [(string) config('backup.mysqldump'), '--single-transaction', '--quick', '--no-tablespaces', '--routines', '--default-character-set=utf8mb4',
            '-h', (string) ($c['host'] ?? '127.0.0.1'), '-P', (string) ($c['port'] ?? 3306), '-u', (string) ($c['username'] ?? ''), (string) ($c['database'] ?? '')];
    }

    /** @param  array<string, mixed>  $c @return list<string> */
    private function mysqlClient(array $c): array
    {
        return [(string) config('backup.mysql'), '--default-character-set=utf8mb4', '-h', (string) ($c['host'] ?? '127.0.0.1'), '-P', (string) ($c['port'] ?? 3306),
            '-u', (string) ($c['username'] ?? ''), (string) ($c['database'] ?? '')];
    }

    /** @param  array<string, mixed>  $c @return list<string> */
    private function pgDump(array $c): array
    {
        return [(string) config('backup.pg_dump'), '--no-owner', '--clean', '--if-exists', '-h', (string) ($c['host'] ?? '127.0.0.1'), '-p', (string) ($c['port'] ?? 5432),
            '-U', (string) ($c['username'] ?? ''), (string) ($c['database'] ?? '')];
    }

    /** @param  array<string, mixed>  $c @return list<string> */
    private function psqlClient(array $c): array
    {
        return [(string) config('backup.psql'), '-v', 'ON_ERROR_STOP=1', '-h', (string) ($c['host'] ?? '127.0.0.1'), '-p', (string) ($c['port'] ?? 5432),
            '-U', (string) ($c['username'] ?? ''), '-d', (string) ($c['database'] ?? '')];
    }

    /** Secrets travel in the process environment, never on the command line. @param list<string> $command @param array<string, string> $env @param resource $gz */
    private function dumpProcess(array $command, array $env, $gz): void
    {
        $error = '';
        $process = new Process($command, null, $env, null, (float) config('backup.timeout'));
        $process->run(function (string $type, string $buffer) use ($gz, &$error): void {
            $type === Process::OUT ? gzwrite($gz, $buffer) : $error .= $buffer;
        });

        $process->isSuccessful() || throw new RuntimeException('The dump tool failed: '.trim($error));
    }

    /** @param list<string> $command @param array<string, string> $env */
    private function restoreProcess(array $command, array $env, string $gzFile): void
    {
        $gz = gzopen($gzFile, 'rb');
        $gz !== false || throw new RuntimeException('Cannot read the backup.');

        $input = (static function () use ($gz): \Generator {
            while (! gzeof($gz)) {
                yield (string) gzread($gz, 65536);
            }
        })();

        $process = new Process($command, null, $env, null, (float) config('backup.timeout'));
        $process->setInput($input);
        $process->run();
        gzclose($gz);

        $process->isSuccessful() || throw new RuntimeException('The restore failed: '.trim($process->getErrorOutput()));
    }

    /** @param  array<string, mixed>  $c */
    private function restoreSqlite(array $c, string $gzFile): void
    {
        $path = (string) ($c['database'] ?? '');
        $path !== '' && $path !== ':memory:' || throw new RuntimeException('An in-memory SQLite database cannot be restored.');

        is_file($path) && copy($path, $path.'.pre-restore');

        $in = gzopen($gzFile, 'rb');
        $out = fopen($path, 'wb');

        while (! gzeof($in)) {
            fwrite($out, (string) gzread($in, 65536));
        }

        gzclose($in);
        fclose($out);
    }
}
