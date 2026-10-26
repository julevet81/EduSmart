<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Support\Rbac\RoleBlueprint;
use Illuminate\Console\Command;

final class SyncTenantRoles extends Command
{
    protected $signature = 'tenants:sync-roles';

    protected $description = 'Add missing default roles/permissions to every tenant (additive, never revokes). Run after each deployment.';

    public function handle(): int
    {
        $count = 0;

        Tenant::query()->orderBy('id')->each(function (Tenant $tenant) use (&$count): void {
            RoleBlueprint::syncFor($tenant);
            $count++;
        });

        $this->info("Roles synced for {$count} tenant(s).");

        return self::SUCCESS;
    }
}
