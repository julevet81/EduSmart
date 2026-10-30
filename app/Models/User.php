<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Auth\HasTwoFactor;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * A login account that belongs to exactly one tenant (school / center).
 * Teachers, students and parents will later have *profile* models linked to a User.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use Auditable, BelongsToTenant, HasApiTokens, HasFactory, HasRoles, HasTwoFactor;

    /** Spatie guard used for every role/permission of tenant users. */
    protected $guard_name = 'web';

    /** @var list<string> */
    protected array $auditExclude = ['last_login_at', 'failed_login_count', 'locked_until', 'two_factor_last_step', 'two_factor_confirmed_at'];

    protected $fillable = ['name', 'email', 'phone', 'password', 'is_active'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    protected function email(): Attribute
    {
        return Attribute::make(
            set: static fn (string $value): string => mb_strtolower(trim($value)),
        );
    }
}
