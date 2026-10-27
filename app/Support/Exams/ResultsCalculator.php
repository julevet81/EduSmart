<?php

declare(strict_types=1);

namespace App\Support\Exams;

use App\Enums\EnrollmentStatus;
use App\Enums\GradeStatus;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\Grade;
use App\Models\Group;
use App\Models\Student;
use App\Models\Subject;
use App\Models\SubjectCoefficient;

/**
 * Averages and ranking of a group, for one term or for the whole year. Integer arithmetic only.
 *
 *  - an exam score is brought to /20, then weighted by the exam coefficient inside its subject;
 *  - the general average weights the subject averages by the subject coefficient of the group's level (or track);
 *    a subject without a defined coefficient weighs 1, and a subject the student has no grade in is left out;
 *  - the year result is the plain mean of the term results;
 *  - absent students are excluded from an exam's average (enter 0 for an unexcused absence if it must count);
 *  - equal averages share the same rank (1, 2, 2, 4).
 * All returned averages are in HUNDREDTHS of a point (14,75 => 1475); use points() to present them.
 */
final class ResultsCalculator
{
    public const SCALE = 20;

    /**
     * @return array{
     *   subjects: list<array{id: int, code: string, name: string, coefficient: int, average: ?int, min: ?int, max: ?int}>,
     *   students: list<array{student: array<string, mixed>, subjects: array<int, ?int>, average: ?int, rank: ?int}>,
     *   stats: array{size: int, graded: int, average: ?int, min: ?int, max: ?int}
     * }
     */
    public function forGroup(Group $group, ?int $termId): array
    {
        $exams = Exam::query()->where('group_id', $group->getKey())
            ->when($termId !== null, fn ($q) => $q->where('term_id', $termId))->get();

        $grades = $exams->isEmpty() ? collect() : Grade::query()
            ->whereIn('exam_id', $exams->modelKeys())
            ->where('status', GradeStatus::Present->value)->whereNotNull('score')->get()
            ->groupBy('exam_id');

        $coefficients = $this->coefficients($group);
        $termIds = $exams->pluck('term_id')->unique()->values();

        /** @var array<int, array<int, array<int, int>>> $subjectByTerm student => term => subject => avg */
        $perTerm = [];

        foreach ($termIds as $tid) {
            $acc = []; // student => subject => [sum(norm*coef), sum(coef)]

            foreach ($exams->where('term_id', $tid) as $exam) {
                foreach ($grades->get($exam->getKey(), collect()) as $grade) {
                    $norm = self::divide($grade->score * self::SCALE, $exam->max_score);
                    $acc[$grade->student_id][$exam->subject_id][0] = ($acc[$grade->student_id][$exam->subject_id][0] ?? 0) + $norm * $exam->coefficient;
                    $acc[$grade->student_id][$exam->subject_id][1] = ($acc[$grade->student_id][$exam->subject_id][1] ?? 0) + $exam->coefficient;
                }
            }

            foreach ($acc as $studentId => $bySubject) {
                foreach ($bySubject as $subjectId => [$sum, $weight]) {
                    $perTerm[$studentId][$tid][$subjectId] = self::divide($sum, $weight);
                }
            }
        }

        $subjectIds = $exams->pluck('subject_id')->unique()->values()->all();
        $subjects = Subject::query()->whereIn('id', $subjectIds)->orderBy('id')->get()->keyBy('id');

        $generalOf = function (array $bySubject) use ($coefficients): ?int {
            $sum = 0;
            $weight = 0;

            foreach ($bySubject as $subjectId => $avg) {
                $coef = $coefficients[$subjectId] ?? 1;
                $sum += $avg * $coef;
                $weight += $coef;
            }

            return $weight === 0 ? null : self::divide($sum, $weight);
        };

        $rows = [];

        foreach ($perTerm as $studentId => $terms) {
            $termGenerals = [];
            $subjectAvgs = [];

            foreach ($terms as $bySubject) {
                $general = $generalOf($bySubject);

                if ($general !== null) {
                    $termGenerals[] = $general;
                }

                foreach ($bySubject as $subjectId => $avg) {
                    $subjectAvgs[$subjectId][] = $avg;
                }
            }

            $rows[$studentId] = [
                'subjects' => array_map(fn (array $v): int => self::divide(array_sum($v), count($v)), $subjectAvgs),
                'average' => $termGenerals === [] ? null : self::divide(array_sum($termGenerals), count($termGenerals)),
            ];
        }

        // Everyone currently enrolled appears, even without a grade yet.
        $enrolled = Enrollment::query()->where('group_id', $group->getKey())
            ->where('status', EnrollmentStatus::Active->value)->pluck('student_id')->all();
        $students = Student::query()->withTrashed()->whereIn('id', array_unique([...array_keys($rows), ...$enrolled]))->get()->keyBy('id');

        $ranked = collect($rows)->pluck('average')->filter(fn ($a) => $a !== null)->sortDesc()->values()->all();

        $list = [];

        foreach ($students as $id => $student) {
            $average = $rows[$id]['average'] ?? null;
            $list[] = [
                'student' => [
                    'id' => $student->getKey(),
                    'registration_number' => $student->registration_number,
                    'first_name' => $student->first_name,
                    'last_name' => $student->last_name,
                ],
                'subjects' => $rows[$id]['subjects'] ?? [],
                'average' => $average,
                'rank' => $average === null ? null : 1 + count(array_filter($ranked, fn (int $a): bool => $a > $average)),
            ];
        }

        usort($list, fn (array $a, array $b): int => [$a['rank'] ?? PHP_INT_MAX, $a['student']['last_name'], $a['student']['first_name']]
            <=> [$b['rank'] ?? PHP_INT_MAX, $b['student']['last_name'], $b['student']['first_name']]);

        $subjectStats = [];

        foreach ($subjectIds as $sid) {
            $values = array_values(array_filter(array_map(fn (array $r): ?int => $r['subjects'][$sid] ?? null, $rows), fn ($v) => $v !== null));
            $subjectStats[] = [
                'id' => (int) $sid,
                'code' => $subjects[$sid]->code,
                'name' => $subjects[$sid]->name,
                'coefficient' => $coefficients[$sid] ?? 1,
            ] + self::summary($values);
        }

        $generals = array_values(array_filter(array_column($rows, 'average'), fn ($v) => $v !== null));

        return [
            'subjects' => $subjectStats,
            'students' => $list,
            'stats' => ['size' => count($list), 'graded' => count($generals)] + self::summary($generals),
        ];
    }

    /** Appreciation code of an average in hundredths (the front end translates it). */
    public static function appreciation(?int $average): ?string
    {
        return match (true) {
            $average === null => null,
            $average >= 1800 => 'excellent',
            $average >= 1600 => 'very_good',
            $average >= 1400 => 'good',
            $average >= 1200 => 'fairly_good',
            $average >= 1000 => 'passable',
            default => 'weak',
        };
    }

    /** Hundredths to points for display. */
    public static function points(?int $hundredths): ?float
    {
        return $hundredths === null ? null : round($hundredths / 100, 2);
    }

    /** Integer division rounded half up. */
    private static function divide(int $numerator, int $denominator): int
    {
        return intdiv(2 * $numerator + $denominator, 2 * $denominator);
    }

    /** @param  list<int>  $values @return array{average: ?int, min: ?int, max: ?int} */
    private static function summary(array $values): array
    {
        return $values === []
            ? ['average' => null, 'min' => null, 'max' => null]
            : ['average' => self::divide(array_sum($values), count($values)), 'min' => min($values), 'max' => max($values)];
    }

    /** @return array<int, int> subject id => coefficient (the track's own value wins over the level's) */
    private function coefficients(Group $group): array
    {
        $map = [];

        $rows = SubjectCoefficient::query()
            ->where('level_id', $group->level_id)
            ->whereIn('track_key', array_unique([0, (int) ($group->track_id ?? 0)]))
            ->orderBy('track_key')->get();

        foreach ($rows as $row) {
            $map[$row->subject_id] = $row->coefficient;
        }

        return $map;
    }
}