<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Subject extends Model
{
    use Auditable, BelongsToTenant;

    protected $fillable = ['code', 'name', 'name_fr', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
