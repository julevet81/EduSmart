<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** starts_on / ends_on are kept as plain "Y-m-d" strings (no date cast) so SQL comparisons stay exact. */
class Holiday extends Model
{
    use Auditable, BelongsToTenant;

    protected $fillable = ['name', 'starts_on', 'ends_on'];
}
