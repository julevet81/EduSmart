<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Guardian extends Model
{
    use Auditable, BelongsToTenant, SoftDeletes;

    protected array $auditExclude = ['national_id'];

    protected $fillable = ['user_id', 'first_name', 'last_name', 'phone', 'email', 'occupation', 'address', 'national_id'];

    protected function casts(): array
    {
        return ['national_id' => 'encrypted'];
    }

    /** @return BelongsToMany<Student, $this> */
    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class)
            ->withPivot(['tenant_id', 'relationship', 'is_primary', 'is_financial_responsible']);
    }
}
