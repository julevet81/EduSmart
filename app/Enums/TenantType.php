<?php

declare(strict_types=1);

namespace App\Enums;

enum TenantType: string
{
    case PrivateSchool = 'private_school';
    case TutoringCenter = 'tutoring_center';
    case TrainingCenter = 'training_center';
}
