<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A subscription plan of the platform (not tenant-owned). Limits are null when unlimited. */
class Plan extends Model
{
    protected $fillable = ['code', 'name', 'price_monthly', 'max_students', 'max_users', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return [
            'price_monthly' => 'integer', 'max_students' => 'integer', 'max_users' => 'integer',
            'is_active' => 'boolean', 'sort_order' => 'integer',
        ];
    }
}
