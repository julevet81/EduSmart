<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TenantStatus;
use App\Enums\TenantType;
use App\Support\Audit\Auditable;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;

class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use Auditable, HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'type', 'status', 'plan',
        'contact_email', 'phone', 'trial_ends_at', 'settings',
    ];

    protected function casts(): array
    {
        return [
            'type' => TenantType::class,
            'status' => TenantStatus::class,
            'trial_ends_at' => 'datetime',
            'settings' => 'array',
        ];
    }

    protected static function booted(): void
    {
        $forget = static function (Tenant $tenant): void {
            Cache::forget(self::cacheKey((string) $tenant->slug));

            $original = $tenant->getOriginal('slug');
            if (is_string($original) && $original !== $tenant->slug) {
                Cache::forget(self::cacheKey($original));
            }
        };

        static::saved($forget);
        static::deleted($forget);
    }

    public static function resolveBySlug(string $slug): ?self
    {
        if (preg_match('/^[a-z0-9](?:[a-z0-9-]{1,61}[a-z0-9])?$/', $slug) !== 1) {
            return null;
        }

        return Cache::remember(
            self::cacheKey($slug),
            (int) config('saas.tenancy.cache_ttl'),
            static fn (): ?self => self::query()->where('slug', $slug)->first(),
        );
    }

    public function auditTenantId(): ?int
    {
        return (int) $this->getKey();
    }

    private static function cacheKey(string $slug): string
    {
        return 'tenant:slug:'.$slug;
    }
}
