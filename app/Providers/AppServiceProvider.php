<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Absence;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\Guardian;
use App\Models\Holiday;
use App\Models\Lesson;
use App\Models\Level;
use App\Models\PlatformAdmin;
use App\Models\Room;
use App\Models\ScheduleSlot;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\Tenant;
use App\Models\Term;
use App\Models\Track;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped: a fresh context per request (Octane-safe) and per queued job.
        $this->app->scoped(TenantContext::class);
    }

    public function boot(): void
    {
        // Short, stable aliases are stored in the DB (tokens, audit events, roles) instead of class names.
        // Every new model must be added here: audit events are named "<alias>.created" etc.
        Relation::morphMap([
            'tenant' => Tenant::class,
            'user' => User::class,
            'platform_admin' => PlatformAdmin::class,
            'academic_year' => AcademicYear::class,
            'term' => Term::class,
            'level' => Level::class,
            'track' => Track::class,
            'subject' => Subject::class,
            'room' => Room::class,
            'group' => Group::class,
            'teacher' => Teacher::class,
            'student' => Student::class,
            'guardian' => Guardian::class,
            'enrollment' => Enrollment::class,
            'holiday' => Holiday::class,
            'schedule_slot' => ScheduleSlot::class,
            'lesson' => Lesson::class,
            'absence' => Absence::class,
        ]);

        Model::shouldBeStrict((bool) config('saas.strict_models'));

        Password::defaults(fn (): Password => $this->app->isProduction()
            ? Password::min(12)->letters()->mixedCase()->numbers()->symbols()->uncompromised()
            : Password::min(10)->letters()->mixedCase()->numbers());

        RateLimiter::for('login', fn (Request $request): array => [
            Limit::perMinute(5)->by('login:'.Str::lower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(30)->by('login-ip:'.$request->ip()),
        ]);

        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(120)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));
    }
}
