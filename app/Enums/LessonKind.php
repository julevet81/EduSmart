<?php

declare(strict_types=1);

namespace App\Enums;

enum LessonKind: string
{
    case Regular = 'regular';
    case Makeup = 'makeup';
}
