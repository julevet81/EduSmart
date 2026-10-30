<?php

declare(strict_types=1);

namespace App\Support\Auth;

use Closure;

/** What differs between the tenant login and the platform login; everything else is shared by AuthFlow. */
final class Realm
{
    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     * @param  list<string>  $abilities
     * @param  Closure(\Illuminate\Database\Eloquent\Model): array<string, mixed>  $present  the account part of the response
     */
    public function __construct(
        public readonly string $type,          // morph alias: user | platform_admin
        public readonly string $model,
        public readonly ?int $tenantId,
        public readonly array $abilities,
        public readonly string $auditPrefix,   // auth | platform
        public readonly Closure $present,
    ) {}
}
