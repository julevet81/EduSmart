<?php

declare(strict_types=1);

namespace App\Actions\Enrollments;

use App\Enums\EnrollmentStatus;
use App\Models\Enrollment;
use App\Models\Group;
use Illuminate\Support\Facades\DB;

/** Moves a student to another group of the same academic year; the history keeps both rows. */
final class TransferEnrollment
{
    public function __construct(private readonly EnrollStudent $enroll, private readonly CloseEnrollment $close) {}

    public function handle(Enrollment $enrollment, Group $target): Enrollment
    {
        if ($enrollment->status !== EnrollmentStatus::Active) {
            abort(422, 'Only an active enrollment can be transferred.');
        }

        if ((int) $target->academic_year_id !== (int) $enrollment->academic_year_id) {
            abort(422, 'The target group belongs to another academic year.');
        }

        if ((int) $target->getKey() === (int) $enrollment->group_id) {
            abort(422, 'The student is already in this group.');
        }

        return DB::transaction(function () use ($enrollment, $target): Enrollment {
            $student = $enrollment->student()->firstOrFail();
            $this->close->handle($enrollment, EnrollmentStatus::Transferred);

            return $this->enroll->handle($student, $target);
        });
    }
}
