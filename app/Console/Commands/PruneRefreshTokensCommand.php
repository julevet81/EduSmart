<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\RefreshToken;
use Illuminate\Console\Command;

/** Removes refresh tokens that can no longer be used (expired, or revoked/used more than a week ago). */
final class PruneRefreshTokensCommand extends Command
{
    protected $signature = 'auth:prune-refresh-tokens';

    protected $description = 'Delete expired and long-revoked refresh tokens';

    public function handle(): int
    {
        $deleted = RefreshToken::query()
            ->where(fn ($q) => $q->where('expires_at', '<', now())
                ->orWhere('family_expires_at', '<', now())
                ->orWhere('revoked_at', '<', now()->subWeek()))
            ->delete();

        $this->info("Deleted {$deleted} refresh token(s).");

        return self::SUCCESS;
    }
}
