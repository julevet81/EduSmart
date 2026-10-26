<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * Records created / updated / deleted events into the append-only audit log.
 * Hidden attributes (password, tokens) are never written. Add more via `protected array $auditExclude`.
 *
 * @mixin Model
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(static function (Model $model): void {
            AuditRecorder::model('created', $model, [], $model->auditableValues($model->getAttributes()));
        });

        static::updated(static function (Model $model): void {
            $new = $model->auditableValues($model->getChanges());

            if ($new === []) {
                return;
            }

            AuditRecorder::model('updated', $model, Arr::only($model->getOriginal(), array_keys($new)), $new);
        });

        static::deleted(static function (Model $model): void {
            AuditRecorder::model('deleted', $model, $model->auditableValues($model->getAttributes()), []);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function auditableValues(array $attributes): array
    {
        $excluded = array_merge(
            $this->getHidden(),
            property_exists($this, 'auditExclude') ? $this->auditExclude : [],
            ['created_at', 'updated_at', 'deleted_at'],
        );

        return Arr::except($attributes, $excluded);
    }

    public function auditTenantId(): ?int
    {
        return app(TenantContext::class)->id();
    }
}
