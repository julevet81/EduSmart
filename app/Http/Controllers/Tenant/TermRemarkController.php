<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\Term;
use App\Models\TermRemark;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TermRemarkController extends Controller
{
    public function upsert(Request $request, string $student, string $term): JsonResponse
    {
        $data = $request->validate(['remark' => ['required', 'string', 'max:500']]);
        $student = Student::query()->findOrFail($student);
        $term = Term::query()->findOrFail($term);

        $remark = TermRemark::query()->updateOrCreate(
            ['student_id' => $student->getKey(), 'term_id' => $term->getKey()],
            ['remark' => $data['remark'], 'created_by' => $request->user()->getKey()],
        );

        return response()->json(['data' => ['student_id' => $student->getKey(), 'term_id' => $term->getKey(), 'remark' => $remark->remark]]);
    }
}
