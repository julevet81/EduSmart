<?php

declare(strict_types=1);

namespace App\Support\Portal;

use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;

/**
 * Which students a portal user may see. A guardian reaches the children linked to them
 * (finance only when flagged as financially responsible); a student reaches only their own record (no finance).
 */
final class PortalAccess
{
    /** @return array<int, bool> student id => may see finance */
    public function students(User $user): array
    {
        $access = [];

        $guardian = Guardian::query()->where('user_id', $user->getKey())->first();

        if ($guardian !== null) {
            foreach ($guardian->students()->get() as $student) {
                $access[$student->getKey()] = (bool) $student->pivot->is_financial_responsible;
            }
        }

        $self = Student::query()->where('user_id', $user->getKey())->value('id');

        if ($self !== null) {
            $access[(int) $self] ??= false;
        }

        return $access;
    }
}
