<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Billing\RecordSubscription;
use App\Actions\Enrollments\EnrollStudent;
use App\Actions\Finance\GenerateInvoices;
use App\Actions\Finance\RecordPayment;
use App\Actions\Schedule\GenerateLessons;
use App\Actions\Schedule\RecordAttendance;
use App\Actions\Tenants\NewTenantData;
use App\Actions\Tenants\ProvisionTenant;
use App\Enums\EnrollmentStatus;
use App\Enums\TenantType;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\FeePlan;
use App\Models\Grade;
use App\Models\Group;
use App\Models\Guardian;
use App\Models\Invoice;
use App\Models\Lesson;
use App\Models\Level;
use App\Models\PlatformAdmin;
use App\Models\Room;
use App\Models\ScheduleSlot;
use App\Models\Student;
use App\Models\StudentFee;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\Tenant;
use App\Models\Term;
use App\Models\User;
use App\Support\Billing\PlanLimits;
use App\Support\Sequences\Sequence;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Realistic demo / manual-testing data. NEVER runs in production.
 *
 *   php artisan db:seed --class=DemoSeeder
 *
 * Creates a platform admin and three institutions:
 *   ecole-nour     private school, paid subscription, a full school term of activity
 *   centre-amal    tutoring centre, still on its free trial
 *   ecole-expiree  trial ended long ago: read-only (writes answer 402)
 *
 * Every demo account uses DemoSeeder::PASSWORD. Random choices are seeded, so the data is the same on every run.
 */
class DemoSeeder extends Seeder
{
    public const PASSWORD = 'Demo-Passw0rd!2026';

    private const MALE = ['أمين', 'يوسف', 'كريم', 'ياسين', 'إلياس', 'رياض', 'أنيس', 'وليد', 'سفيان', 'ماهر', 'حمزة', 'نادر'];

    private const FEMALE = ['ملاك', 'ياسمين', 'سارة', 'ريم', 'إيمان', 'نور', 'آية', 'مريم', 'لينا', 'هبة', 'رانيا', 'سلمى'];

    private const LAST = ['بن علي', 'حداد', 'مرابط', 'بوزيد', 'قاسمي', 'شريف', 'عمراني', 'بلحاج', 'سعيدي', 'زروقي', 'تواتي', 'بوعلام'];

    private const FATHERS = ['محمد', 'عبد القادر', 'أحمد', 'مصطفى', 'بشير', 'عمر', 'سمير', 'فاروق', 'نبيل', 'إسماعيل', 'جمال', 'رشيد'];

    private const TEACHERS = [['كريم', 'حداد'], ['سلمى', 'بوعلام'], ['فاروق', 'مرابط'], ['نادية', 'شريف'], ['عادل', 'تواتي']];

    private const START_TIMES = ['08:00', '10:00', '13:00'];

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('The demo seeder never runs in production.');
        }

        if (Tenant::query()->where('slug', 'ecole-nour')->exists()) {
            $this->command?->warn('Demo data is already present. Use "php artisan migrate:fresh --seed --seeder=DemoSeeder" to rebuild it.');

            return;
        }

        mt_srand(2026);

        DB::transaction(function (): void {
            $admin = PlatformAdmin::query()->firstOrCreate(
                ['email' => 'root@demo.test'],
                ['name' => 'Demo Root', 'password' => self::PASSWORD, 'is_active' => true],
            );

            $nour = $this->provision('ecole-nour', 'مدرسة النور الخاصة', TenantType::PrivateSchool, 'الجزائر العاصمة');
            $this->populate($nour['tenant'], $nour['owner'], groups: 3, students: 24);
            app(RecordSubscription::class)->handle($nour['tenant'], [
                'plan' => 'standard', 'months' => 12, 'method' => 'bank_transfer', 'reference' => 'DEMO-0001',
            ], (int) $admin->getKey());

            $amal = $this->provision('centre-amal', 'مركز الأمل للدعم المدرسي', TenantType::TutoringCenter, 'وهران');
            $this->populate($amal['tenant'], $amal['owner'], groups: 2, students: 12);

            $old = $this->provision('ecole-expiree', 'مدرسة منتهية الاشتراك', TenantType::PrivateSchool, 'قسنطينة');
            $old['tenant']->update(['trial_ends_at' => now()->subDays(60)->toDateString()]);
        });

        $this->command?->info('Demo data created. Every account uses the password: '.self::PASSWORD);
        $this->command?->table(['Where', 'Header X-Tenant', 'Login'], [
            ['Platform', '-', 'root@demo.test'],
            ['Tenants', 'ecole-nour | centre-amal | ecole-expiree', 'owner@<slug>.demo'],
            ['Roles', 'ecole-nour | centre-amal', 'manager@ | secretary@ | accountant@ | teacher@ | parent@ | student@  + <slug>.demo'],
        ]);
    }

    /** @return array{tenant: Tenant, owner: User} */
    private function provision(string $slug, string $name, TenantType $type, string $city): array
    {
        $result = app(ProvisionTenant::class)->handle(new NewTenantData(
            name: $name, slug: $slug, type: $type, contactEmail: "contact@{$slug}.demo", phone: '0550123456',
            ownerName: 'مدير '.$city, ownerEmail: "owner@{$slug}.demo", ownerPassword: self::PASSWORD,
        ));

        return ['tenant' => $result->tenant, 'owner' => $result->owner];
    }

    private function populate(Tenant $tenant, User $owner, int $groups, int $students): void
    {
        app(TenantContext::class)->run($tenant, function () use ($tenant, $owner, $groups, $students): void {
            $slug = $tenant->slug;
            $staff = $this->staff($slug);
            $year = $this->year();
            $subjects = Subject::query()->orderBy('id')->limit(6)->get();
            $rooms = collect(['قاعة 1', 'قاعة 2', 'قاعة 3', 'مخبر'])->map(fn (string $n): Room => Room::query()->create(['name' => $n, 'capacity' => 30]));

            $teachers = $this->teachers($subjects, $staff['teacher']);
            $levels = Level::query()->where('code', 'like', '%AM')->orderBy('sort_order')->limit($groups)->get();
            $groupModels = $levels->values()->map(fn (Level $level, int $i): Group => Group::query()->create([
                'academic_year_id' => $year->id, 'level_id' => $level->id, 'name' => $level->code.'-A', 'capacity' => 25, 'is_active' => true,
            ]));

            $people = $this->students($students, $groupModels, $year);
            $this->schedule($year, $groupModels, $subjects, $teachers, $rooms);
            $this->attendance($staff['secretary']);
            $this->finance($year, $people['students'], $owner, $slug);
            $this->exams($year, $groupModels, $subjects, $teachers, $owner);
            $this->portal($people['guardians'][0], $people['students'][1]);
        });
    }

    /** @return array<string, User> */
    private function staff(string $slug): array
    {
        $users = [];

        foreach (['manager' => 'مدير الدراسة', 'secretary' => 'أمينة المدرسة', 'accountant' => 'المحاسب', 'teacher' => 'الأستاذ كريم'] as $role => $name) {
            $user = User::query()->create(['name' => $name, 'email' => "{$role}@{$slug}.demo", 'password' => self::PASSWORD, 'is_active' => true]);
            $user->assignRole($role);
            $users[$role] = $user;
        }

        return $users;
    }

    private function year(): AcademicYear
    {
        $start = CarbonImmutable::create((int) (now()->month >= 9 ? now()->year : now()->year - 1), 9, 1);
        $year = AcademicYear::query()->create([
            'name' => $start->year.'/'.($start->year + 1), 'starts_on' => $start->toDateString(),
            'ends_on' => $start->addYear()->setMonth(6)->endOfMonth()->toDateString(), 'is_current' => true,
        ]);

        foreach ([[1, 'الفصل الأول', '09-01', '12-20', 0], [2, 'الفصل الثاني', '01-05', '03-25', 1], [3, 'الفصل الثالث', '04-05', '06-30', 1]] as [$n, $name, $from, $to, $plus]) {
            Term::query()->create([
                'academic_year_id' => $year->id, 'number' => $n, 'name' => $name,
                'starts_on' => ($start->year + $plus).'-'.$from, 'ends_on' => ($start->year + $plus).'-'.$to,
            ]);
        }

        return $year;
    }

    /** @param \Illuminate\Support\Collection<int, Subject> $subjects @return \Illuminate\Support\Collection<int, Teacher> */
    private function teachers($subjects, User $teacherUser)
    {
        return collect(self::TEACHERS)->map(function (array $name, int $i) use ($subjects, $teacherUser): Teacher {
            $subject = $subjects[$i % $subjects->count()];
            $teacher = Teacher::query()->create([
                'user_id' => $i === 0 ? $teacherUser->id : null, 'first_name' => $name[0], 'last_name' => $name[1],
                'gender' => $i % 2 === 0 ? 'male' : 'female', 'phone' => '055012345'.$i, 'specialization' => $subject->name,
                'hired_on' => now()->subYears(2 + $i)->toDateString(), 'is_active' => true,
            ]);
            $teacher->subjects()->syncWithoutDetaching([$subject->id => ['tenant_id' => $subject->tenant_id]]);

            return $teacher;
        });
    }

    /** @param \Illuminate\Support\Collection<int, Group> $groups @return array{students: list<Student>, guardians: list<Guardian>} */
    private function students(int $count, $groups, AcademicYear $year): array
    {
        $families = intdiv($count * 3, 4);
        $guardians = [];
        $students = [];
        $enroll = app(EnrollStudent::class);
        $on = CarbonImmutable::parse($year->starts_on)->addDays(4);

        for ($i = 0; $i < $count; $i++) {
            $familyIndex = $i < $families ? $i : $i - $families;
            $last = self::LAST[$familyIndex % count(self::LAST)];

            if (! isset($guardians[$familyIndex])) {
                $guardians[$familyIndex] = Guardian::query()->create([
                    'first_name' => self::FATHERS[$familyIndex % count(self::FATHERS)], 'last_name' => $last,
                    'phone' => '066012'.str_pad((string) (100 + $familyIndex), 4, '0', STR_PAD_LEFT),
                    'email' => 'famille'.$familyIndex.'@example.test', 'occupation' => 'موظف', 'address' => 'حي 5 جويلية',
                ]);
            }

            $female = $i % 2 === 1;
            $student = new Student([
                'first_name' => ($female ? self::FEMALE : self::MALE)[$i % 12], 'last_name' => $last, 'gender' => $female ? 'female' : 'male',
                'birth_date' => now()->subYears(12 + ($i % $groups->count()))->subDays(mt_rand(0, 300))->toDateString(),
                'wilaya_code' => 16, 'phone' => null, 'is_active' => true,
            ]);
            $student->registration_number = sprintf('%s-%05d', now()->format('y'), Sequence::next('student_registration'));
            $student->save();

            $student->guardians()->attach($guardians[$familyIndex]->id, [
                'tenant_id' => $student->tenant_id, 'relationship' => 'father', 'is_primary' => true, 'is_financial_responsible' => true,
            ]);

            $enroll->handle($student, $groups[$i % $groups->count()], $on);
            $students[] = $student;
        }

        return ['students' => $students, 'guardians' => array_values($guardians)];
    }

    /** Four weekly slots per group at different hours, so no teacher, room or group ever clashes. */
    private function schedule(AcademicYear $year, $groups, $subjects, $teachers, $rooms): void
    {
        foreach ($groups as $g => $group) {
            $start = self::START_TIMES[$g % count(self::START_TIMES)];
            $end = CarbonImmutable::createFromFormat('H:i', $start)->addMinutes(90)->format('H:i');

            foreach ([0, 1, 2, 3] as $n => $weekday) {
                ScheduleSlot::query()->create([
                    'academic_year_id' => $year->id, 'group_id' => $group->id, 'subject_id' => $subjects[($g + $n) % $subjects->count()]->id,
                    'teacher_id' => $teachers[($g + $n) % $teachers->count()]->id, 'room_id' => $rooms[$g % $rooms->count()]->id,
                    'weekday' => $weekday, 'starts_at' => $start, 'ends_at' => $end,
                    'valid_from' => $year->starts_on->toDateString(), 'valid_until' => $year->ends_on->toDateString(), 'is_active' => true,
                ]);
            }
        }

        $from = CarbonImmutable::today()->subDays(21);
        $from = $from->lessThan($year->starts_on) ? CarbonImmutable::parse($year->starts_on) : $from;
        app(GenerateLessons::class)->handle($from, CarbonImmutable::today()->addDays(14));
    }

    /** The first enrolled student of each group is absent from every lesson (so the absence alert has something to find). */
    private function attendance(User $actor): void
    {
        $record = app(RecordAttendance::class);

        Lesson::query()->where('date', '<=', today()->toDateString())->orderBy('date')->each(function (Lesson $lesson) use ($record, $actor): void {
            $ids = Enrollment::query()->where('group_id', $lesson->group_id)->where('status', EnrollmentStatus::Active->value)->orderBy('id')->pluck('student_id')->all();
            $absences = [];

            foreach ($ids as $i => $id) {
                if ($i === 0) {
                    $absences[] = ['student_id' => $id, 'type' => 'absent'];
                } elseif (mt_rand(1, 12) === 1) {
                    $absences[] = ['student_id' => $id, 'type' => mt_rand(0, 1) === 1 ? 'late' : 'absent'];
                }
            }

            $record->handle($lesson, $absences, $actor);
        });
    }

    /** @param list<Student> $students */
    private function finance(AcademicYear $year, array $students, User $actor, string $slug): void
    {
        $tuition = FeePlan::query()->create(['academic_year_id' => $year->id, 'name' => 'Frais de scolarité', 'billing_mode' => 'monthly', 'amount' => 600000, 'due_day' => 5, 'is_active' => true]);
        $registration = FeePlan::query()->create(['academic_year_id' => $year->id, 'name' => 'Frais d\'inscription', 'billing_mode' => 'one_time', 'amount' => 200000, 'is_active' => true]);

        foreach ($students as $i => $student) {
            $discount = $i % 7 === 6 ? ['discount_type' => 'percent', 'discount_value' => 10, 'discount_reason' => 'Fratrie'] : [];
            foreach ([$tuition, $registration] as $plan) {
                StudentFee::query()->create(['student_id' => $student->id, 'fee_plan_id' => $plan->id, 'starts_on' => $year->starts_on->toDateString(), 'is_active' => true] + ($plan === $tuition ? $discount : []));
            }
        }

        $generate = app(GenerateInvoices::class);
        for ($month = CarbonImmutable::parse($year->starts_on)->startOfMonth(); $month->lessThanOrEqualTo(now()); $month = $month->addMonth()) {
            $generate->handle($month->format('Y-m'), null, $actor);
        }

        $pay = app(RecordPayment::class);
        foreach ($students as $i => $student) {
            $due = (int) Invoice::query()->where('student_id', $student->id)->sum('total_amount');
            $amount = match ($i % 4) {
                0 => 0,                                // owes everything
                1 => intdiv($due, 2000) * 1000,        // pays about half
                default => $due,                       // settled
            };

            if ($amount > 0) {
                $pay->handle($student, ['amount' => $amount, 'method' => 'cash', 'paid_on' => now()->subDays(3 + $i % 10)->toDateString(), 'idempotency_key' => "demo-{$slug}-{$student->id}"], $actor);
            }
        }
    }

    private function exams(AcademicYear $year, $groups, $subjects, $teachers, User $actor): void
    {
        $term = Term::query()->where('academic_year_id', $year->id)->where('number', 1)->firstOrFail();
        $date = CarbonImmutable::parse($term->starts_on)->addDays(25);
        $date = $date->greaterThan(now()) ? CarbonImmutable::today() : $date;

        foreach ($groups as $g => $group) {
            $ids = Enrollment::query()->where('group_id', $group->id)->pluck('student_id');

            foreach ([0, 1, 2] as $n) {
                $subject = $subjects[($g + $n) % $subjects->count()];
                $exam = Exam::query()->create([
                    'academic_year_id' => $year->id, 'term_id' => $term->id, 'group_id' => $group->id, 'subject_id' => $subject->id,
                    'teacher_id' => $teachers[($g + $n) % $teachers->count()]->id, 'name' => 'اختبار '.$subject->name,
                    'kind' => $n === 0 ? 'exam' : 'test', 'date' => $date->toDateString(), 'max_score' => 20, 'coefficient' => $n === 0 ? 2 : 1, 'created_by' => $actor->id,
                ]);

                foreach ($ids as $studentId) {
                    $absent = mt_rand(1, 15) === 1;
                    Grade::query()->create([
                        'exam_id' => $exam->id, 'student_id' => $studentId, 'status' => $absent ? 'absent' : 'present',
                        'score' => $absent ? null : mt_rand(12, 39) * 50, 'entered_by' => $actor->id,   // hundredths: 6.00 .. 19.50
                    ]);
                }
            }
        }
    }

    /** One parent and one student with a portal login (portal accounts are free: they do not use the plan quota). */
    private function portal(Guardian $guardian, Student $student): void
    {
        foreach ([['parent', $guardian], ['student', $student]] as [$role, $profile]) {
            $user = PlanLimits::skipping(fn (): User => User::query()->create([
                'name' => trim($profile->first_name.' '.$profile->last_name),
                'email' => $role.'@'.app(TenantContext::class)->require()->slug.'.demo',
                'password' => self::PASSWORD, 'is_active' => true,
            ]));
            $user->syncRoles([$role]);
            $profile->update(['user_id' => $user->id]);
        }
    }
}
