<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Enums\Gender;
use App\Http\Resources\StudentResource;
use App\Models\Student;
use App\Support\Sequences\Sequence;
use App\Support\Validation\TenantRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class StudentController extends ResourceController
{
    protected function model(): string
    {
        return Student::class;
    }

    protected function resource(): string
    {
        return StudentResource::class;
    }

    protected function with(): array
    {
        return ['guardians', 'currentEnrollment'];
    }

    protected function rules(Request $request, ?Model $model): array
    {
        return [
            'user_id' => ['nullable', 'integer', TenantRules::exists('users'), TenantRules::unique('students', 'user_id', $model?->getKey())],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'first_name_latin' => ['nullable', 'string', 'max:255'],
            'last_name_latin' => ['nullable', 'string', 'max:255'],
            'gender' => ['nullable', Rule::enum(Gender::class)],
            'birth_date' => ['nullable', 'date', 'before:today', 'after:1950-01-01'],
            'birth_place' => ['nullable', 'string', 'max:255'],
            'birth_certificate_number' => ['nullable', 'string', 'max:64'],
            'national_id' => ['nullable', 'string', 'max:64'],
            'wilaya_code' => ['nullable', 'integer', 'between:1,58'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'regex:'.TenantRules::PHONE],
            'medical_notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ];
    }

    protected function persist(array $data, ?Model $model): Model
    {
        if ($model !== null) {
            return parent::persist($data, $model);
        }

        // Called inside the store() transaction, so a failed insert releases the number.
        $number = sprintf('%s-%05d', now()->format('y'), Sequence::next('student_registration'));

        // registration_number is not mass-assignable on purpose: clients can never choose it.
        $student = new Student($data);
        $student->registration_number = $number;
        $student->save();

        return $student;
    }

    protected function filter(Builder $query, Request $request): void
    {
        $request->validate([
            'group_id' => ['sometimes', 'integer'],
            'level_id' => ['sometimes', 'integer'],
            'academic_year_id' => ['sometimes', 'integer'],
        ]);

        $query->when($request->query('q'), fn (Builder $b, string $q) => $b->where(
            fn (Builder $w) => $w->where('first_name', 'like', $this->like($q))
                ->orWhere('last_name', 'like', $this->like($q))
                ->orWhere('registration_number', 'like', $this->like($q))
        ));

        $filters = array_filter([
            'group_id' => $request->query('group_id'),
            'academic_year_id' => $request->query('academic_year_id'),
        ]);
        $levelId = $request->query('level_id');

        if ($filters !== [] || $levelId !== null) {
            $query->whereHas('enrollments', function (Builder $e) use ($filters, $levelId): void {
                $e->where('status', 'active')->where($filters);
                if ($levelId !== null) {
                    $e->whereHas('group', fn (Builder $g) => $g->where('level_id', $levelId));
                }
            });
        }
    }
}
