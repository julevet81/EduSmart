<?php

declare(strict_types=1);

namespace App\Enums;

enum Relationship: string
{
    case Father = 'father';
    case Mother = 'mother';
    case Grandparent = 'grandparent';
    case Sibling = 'sibling';
    case LegalGuardian = 'legal_guardian';
    case Other = 'other';
}
