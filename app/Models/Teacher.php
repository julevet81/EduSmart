<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Gender;
use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Teacher extends Model
{
    use Auditable, BelongsToTenant, SoftDeletes;

    protected $fillable = [
        'user_id', 'first_name', 'last_name', 'first_name_latin', 'last_name_latin',
        'gender', 'phone', 'email', 'specialization', 'hired_on', 'is_active',
    ];

    protected function casts(): array
    {
        return ['gender' => Gender::class, 'hired_on' => 'date', 'is_active' => 'boolean'];
    }

    /** @return BelongsToMany<Subject, $this> */
    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class)->withPivot('tenant_id');
    }
}
