<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Exceptions\TenantContextException;
use App\Models\Tenant;
use Spatie\Permission\PermissionRegistrar;

/**
 * Holds the tenant of the current request / job.
 * Registered as a *scoped* binding so it is reset per request (Octane) and per queued job.
 */
final class TenantContext
{
    private ?Tenant $tenant = null;

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    }

    public function forget(): void
    {
        $this->tenant = null;
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    }

    public function get(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant === null ? null : (int) $this->tenant->getKey();
    }

    public function require(): Tenant
    {
        return $this->tenant ?? throw new TenantContextException('No tenant is resolved for this operation.');
    }

    /**
     * Run a callback inside the given tenant, then restore the previous context.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function run(Tenant $tenant, callable $callback): mixed
    {
        $previous = $this->tenant;
        $this->set($tenant);

        try {
            return $callback();
        } finally {
            $previous !== null ? $this->set($previous) : $this->forget();
        }
    }
}
