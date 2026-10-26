<?php

declare(strict_types=1);

use App\Actions\Tenants\NewTenantData;
use App\Actions\Tenants\ProvisionTenant;
use App\Enums\TenantType;
use App\Enums\EnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\Level;
use App\Models\PlatformAdmin;
use App\Models\Room;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Feature');

const TEST_PASSWORD = 'Str0ng-Passw0rd!';

/** Creates a tenant with default roles, curriculum and an owner. @return array{0: Tenant, 1: User} */
function provisionTenant(string $slug = 'school-a'): array
{
    $result = app(ProvisionTenant::class)->handle(new NewTenantData(
        name: ucfirst($slug),
        slug: $slug,
        type: TenantType::PrivateSchool,
        contactEmail: null,
        phone: null,
        ownerName: 'Owner '.$slug,
        ownerEmail: "owner@{$slug}.test",
        ownerPassword: TEST_PASSWORD,
    ));

    return [$result->tenant, $result->owner];
}

/** Run code inside a tenant (needed to touch tenant-owned models from tests). */
function inTenant(Tenant $tenant, callable $callback): mixed
{
    return app(TenantContext::class)->run($tenant, $callback);
}

/** Prepare a clean request context: no stale auth guard, no stale tenant, fresh headers. */
function api(?Tenant $tenant = null, ?string $token = null): Tests\TestCase
{
    app('auth')->forgetGuards();
    app(TenantContext::class)->forget();

    $headers = [];
    if ($tenant !== null) {
        $headers['X-Tenant'] = $tenant->slug;
    }
    if ($token !== null) {
        $headers['Authorization'] = 'Bearer '.$token;
    }

    return test()->flushHeaders()->withHeaders($headers);
}

function loginAs(Tenant $tenant, string $email, string $password = TEST_PASSWORD): TestResponse
{
    return api($tenant)->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password]);
}

function tokenFor(Tenant $tenant, string $email): string
{
    return (string) loginAs($tenant, $email)->assertOk()->json('token');
}

function makePlatformAdmin(): PlatformAdmin
{
    return PlatformAdmin::query()->create([
        'name' => 'Root',
        'email' => 'root@platform.test',
        'password' => TEST_PASSWORD,
        'is_active' => true,
    ]);
}

function platformToken(): string
{
    makePlatformAdmin();

    return (string) api()->postJson('/api/v1/platform/auth/login', [
        'email' => 'root@platform.test',
        'password' => TEST_PASSWORD,
    ])->assertOk()->json('token');
}

/** Creates a tenant and returns it with its owner's token. @return array{0: Tenant, 1: string} */
function ownerSession(string $slug = 'school-a'): array
{
    [$tenant, $owner] = provisionTenant($slug);

    return [$tenant, tokenFor($tenant, $owner->email)];
}

/** Creates a user holding one role in the tenant and returns its token. */
function roleToken(Tenant $tenant, string $role): string
{
    $email = "{$role}@{$tenant->slug}.test";

    inTenant($tenant, function () use ($email, $role): void {
        User::factory()->create(['email' => $email])->assignRole($role);
    });

    return tokenFor($tenant, $email);
}

function makeYear(Tenant $tenant, string $name = '2026/2027'): AcademicYear
{
    return inTenant($tenant, fn () => AcademicYear::query()->create([
        'name' => $name, 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30', 'is_current' => true,
    ]));
}

function makeGroup(Tenant $tenant, AcademicYear $year, string $name = 'G1', ?int $capacity = null): Group
{
    return inTenant($tenant, fn () => Group::query()->create([
        'academic_year_id' => $year->id,
        'level_id' => Level::query()->where('code', '1AM')->value('id'),
        'name' => $name,
        'capacity' => $capacity,
    ]));
}

function makeStudent(Tenant $tenant, string $first = 'Amine', string $last = 'Benali'): Student
{
    return inTenant($tenant, function () use ($first, $last): Student {
        $student = new Student(['first_name' => $first, 'last_name' => $last]);
        $student->registration_number = '26-'.fake()->unique()->numerify('#####');
        $student->save();

        return $student;
    });
}

/** An academic year that contains today and the following months (lesson tests use dates relative to now). */
function makeCurrentYear(Tenant $tenant): AcademicYear
{
    return inTenant($tenant, fn () => AcademicYear::query()->create([
        'name' => 'Current',
        'starts_on' => now()->subMonths(2)->toDateString(),
        'ends_on' => now()->addMonths(10)->toDateString(),
        'is_current' => true,
    ]));
}

function makeTeacher(Tenant $tenant, ?int $userId = null, string $first = 'Karim'): Teacher
{
    return inTenant($tenant, fn () => Teacher::query()->create([
        'user_id' => $userId, 'first_name' => $first, 'last_name' => 'Haddad',
    ]));
}

function makeRoom(Tenant $tenant, string $name = 'R1'): Room
{
    return inTenant($tenant, fn () => Room::query()->create(['name' => $name, 'capacity' => 30]));
}

function subjectId(Tenant $tenant, string $code = 'MATH'): int
{
    return (int) inTenant($tenant, fn () => Subject::query()->where('code', $code)->value('id'));
}

/** The next Monday strictly after today (Carbon weekday 1), so generated lessons are always in the future. */
function nextMonday(): CarbonImmutable
{
    return CarbonImmutable::today()->next(CarbonImmutable::MONDAY);
}

function makeLesson(Tenant $tenant, Group $group, Teacher $teacher, string $date, string $start = '08:00', string $end = '09:30', ?Room $room = null, array $overrides = []): Lesson
{
    return inTenant($tenant, fn () => Lesson::query()->create(array_merge([
        'academic_year_id' => $group->academic_year_id,
        'group_id' => $group->id,
        'subject_id' => subjectId($tenant),
        'teacher_id' => $teacher->id,
        'room_id' => $room?->id,
        'kind' => 'regular',
        'date' => $date,
        'starts_at' => $start,
        'ends_at' => $end,
        'status' => 'scheduled',
    ], $overrides)));
}

function enrollNow(Tenant $tenant, Student $student, Group $group, ?string $on = null): Enrollment
{
    return inTenant($tenant, fn () => Enrollment::query()->create([
        'student_id' => $student->id,
        'group_id' => $group->id,
        'academic_year_id' => $group->academic_year_id,
        'status' => EnrollmentStatus::Active,
        'enrolled_on' => $on ?? now()->subDays(30)->toDateString(),
    ]));
}

/** Body of a recurring slot (Monday 08:00-09:30 by default). */
function slotBody(Tenant $tenant, Group $group, Teacher $teacher, ?Room $room = null, array $overrides = []): array
{
    return array_merge([
        'group_id' => $group->id,
        'subject_id' => subjectId($tenant),
        'teacher_id' => $teacher->id,
        'room_id' => $room?->id,
        'weekday' => 1,
        'starts_at' => '08:00',
        'ends_at' => '09:30',
    ], $overrides);
}

/** Token of a user holding $role AND linked to a Teacher profile (for row-level teacher tests). @return array{0: string, 1: Teacher} */
function teacherSession(Tenant $tenant, string $first = 'Mine'): array
{
    $token = roleToken($tenant, 'teacher');
    $userId = inTenant($tenant, fn () => User::query()->where('email', "teacher@{$tenant->slug}.test")->value('id'));

    return [$token, makeTeacher($tenant, (int) $userId, $first)];
}
