<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ExpenseCategory extends Model
{
    use Auditable, BelongsToTenant;

    protected $fillable = ['name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
