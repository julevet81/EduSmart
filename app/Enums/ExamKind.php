<?php

declare(strict_types=1);

namespace App\Enums;

enum ExamKind: string
{
    case Test = 'test';
    case Exam = 'exam';
    case Homework = 'homework';
    case Oral = 'oral';
    case Project = 'project';
}
