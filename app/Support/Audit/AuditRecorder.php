<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Models\AuditLog;
use App\Support\Impersonation\Impersonation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

final class AuditRecorder
{
    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    public static function model(string $event, Model $model, array $old, array $new): void
    {
        self::write(
            event: $model->getMorphClass().'.'.$event,
            tenantId: method_exists($model, 'auditTenantId') ? $model->auditTenantId() : app(TenantContext::class)->id(),
            auditable: $model,
            old: $old,
            new: $new,
        );
    }

    /**
     * Custom business event, e.g. "auth.login_failed" or "user.roles_synced".
     *
     * @param  array<string, mixed>  $context
     */
    public static function event(string $event, array $context = [], ?int $tenantId = null): void
    {
        self::write(
            event: $event,
            tenantId: $tenantId ?? app(TenantContext::class)->id(),
            auditable: null,
            old: [],
            new: $context,
        );
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    private static function write(string $event, ?int $tenantId, ?Model $auditable, array $old, array $new): void
    {
        $actor = request()->user();

        AuditLog::query()->create([
            'tenant_id' => $tenantId,
            'actor_type' => $actor instanceof Model ? $actor->getMorphClass() : null,
            'actor_id' => $actor instanceof Model ? (string) $actor->getKey() : null,
            'impersonator_id' => Impersonation::adminId(),
            'event' => $event,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable === null ? null : (string) $auditable->getKey(),
            'old_values' => $old === [] ? null : $old,
            'new_values' => $new === [] ? null : $new,
            'ip_address' => request()->ip(),
            'user_agent' => mb_substr((string) request()->userAgent(), 0, 255) ?: null,
        ]);
    }
}
