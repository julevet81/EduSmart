<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Gender;
use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Student extends Model
{
    use Auditable, BelongsToTenant, SoftDeletes;

    /** Sensitive values never reach the audit log. */
    protected array $auditExclude = ['national_id', 'medical_notes'];

    protected $fillable = [
        'user_id', 'first_name', 'last_name', 'first_name_latin', 'last_name_latin', 'gender',
        'birth_date', 'birth_place', 'birth_certificate_number', 'national_id', 'wilaya_code',
        'address', 'phone', 'medical_notes', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'gender' => Gender::class,
            'birth_date' => 'date',
            'national_id' => 'encrypted',
            'medical_notes' => 'encrypted',
            'wilaya_code' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsToMany<Guardian, $this> */
    public function guardians(): BelongsToMany
    {
        return $this->belongsToMany(Guardian::class)
            ->withPivot(['tenant_id', 'relationship', 'is_primary', 'is_financial_responsible']);
    }

    /** @return HasMany<Enrollment, $this> */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    /** @return HasOne<Enrollment, $this> */
    public function currentEnrollment(): HasOne
    {
        return $this->hasOne(Enrollment::class)->where('status', 'active')->latestOfMany();
    }
}
