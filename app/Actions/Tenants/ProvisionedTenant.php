<?php

declare(strict_types=1);

namespace App\Actions\Tenants;

use App\Models\Tenant;
use App\Models\User;

final readonly class ProvisionedTenant
{
    public function __construct(
        public Tenant $tenant,
        public User $owner,
    ) {}
}
