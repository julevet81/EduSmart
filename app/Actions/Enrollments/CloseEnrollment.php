<?php

declare(strict_types=1);

namespace App\Actions\Enrollments;

use App\Enums\EnrollmentStatus;
use App\Models\Enrollment;

final class CloseEnrollment
{
    public function handle(Enrollment $enrollment, EnrollmentStatus $status, ?string $notes = null): Enrollment
    {
        if ($enrollment->status !== EnrollmentStatus::Active) {
            abort(422, 'Only an active enrollment can be closed.');
        }

        if ($status === EnrollmentStatus::Active) {
            abort(422, 'Choose a closing status.');
        }

        $enrollment->update([
            'status' => $status,
            'ended_on' => now()->toDateString(),
            'notes' => $notes ?? $enrollment->notes,
        ]);

        return $enrollment;
    }
}
