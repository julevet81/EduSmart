<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Exceptions\TenantContextException;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Put this trait on EVERY tenant-owned model (students, groups, invoices, ...).
 * The table must have a non-null `tenant_id` column, indexed (composite indexes should start with it).
 *
 * @mixin Model
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(static function (Model $model): void {
            $contextId = app(TenantContext::class)->id();
            $modelTenantId = $model->getAttribute('tenant_id');

            if ($modelTenantId === null) {
                if ($contextId === null) {
                    throw new TenantContextException('Cannot create '.$model::class.' without a tenant context.');
                }

                $model->setAttribute('tenant_id', $contextId);

                return;
            }

            if ($contextId !== null && (int) $modelTenantId !== $contextId) {
                throw new TenantContextException('Attempt to create '.$model::class.' for a different tenant.');
            }
        });
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
