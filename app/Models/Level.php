<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Cycle;
use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Level extends Model
{
    use Auditable, BelongsToTenant;

    protected $fillable = ['code', 'name', 'name_fr', 'cycle', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['cycle' => Cycle::class, 'sort_order' => 'integer', 'is_active' => 'boolean'];
    }
}
