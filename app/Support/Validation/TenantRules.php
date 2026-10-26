<?php

declare(strict_types=1);

namespace App\Support\Validation;

use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

/**
 * Validation rules that query tables directly (bypassing Eloquent scopes), so they MUST be tenant-aware.
 * Never use Rule::exists()/Rule::unique() on a tenant table without these helpers.
 */
final class TenantRules
{
    public const PHONE = '/^(?:\+213|0)[1-9]\d{7,8}$/';

    public static function exists(string $table, string $column = 'id'): Exists
    {
        return Rule::exists($table, $column)->where('tenant_id', self::tenantId());
    }

    public static function unique(string $table, string $column, ?int $ignoreId = null): Unique
    {
        $rule = Rule::unique($table, $column)->where('tenant_id', self::tenantId());

        return $ignoreId === null ? $rule : $rule->ignore($ignoreId);
    }

    private static function tenantId(): int
    {
        return (int) app(TenantContext::class)->require()->getKey();
    }
}
