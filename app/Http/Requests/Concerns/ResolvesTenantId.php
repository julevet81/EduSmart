<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Support\Tenancy\TenantContext;

trait ResolvesTenantId
{
    protected function tenantId(): int
    {
        return app(TenantContext::class)->require()->getKey();
    }
}
