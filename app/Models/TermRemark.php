<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** The class council's remark on a student's term, printed on the report card. */
class TermRemark extends Model
{
    use Auditable, BelongsToTenant;

    protected $fillable = ['student_id', 'term_id', 'remark', 'created_by'];
}
