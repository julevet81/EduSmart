<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Non-teaching staff paid by the institution (secretary, cleaner, guard...). Deactivated, never deleted: payslips refer to them. */
class StaffMember extends Model
{
    use Auditable, BelongsToTenant;

    protected $fillable = ['first_name', 'last_name', 'position', 'phone', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
