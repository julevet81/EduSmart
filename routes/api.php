<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\PlatformAuthController;
use App\Http\Controllers\Auth\TenantAuthController;
use App\Http\Controllers\Platform\TenantController;
use App\Http\Controllers\Tenant\AbsenceController;
use App\Http\Controllers\Tenant\AcademicYearController;
use App\Http\Controllers\Tenant\AttendanceController;
use App\Http\Controllers\Tenant\AuditLogController;
use App\Http\Controllers\Tenant\EnrollmentController;
use App\Http\Controllers\Tenant\FeePlanController;
use App\Http\Controllers\Tenant\FinanceReportController;
use App\Http\Controllers\Tenant\GroupController;
use App\Http\Controllers\Tenant\GuardianController;
use App\Http\Controllers\Tenant\HolidayController;
use App\Http\Controllers\Tenant\InvoiceController;
use App\Http\Controllers\Tenant\LessonController;
use App\Http\Controllers\Tenant\LevelController;
use App\Http\Controllers\Tenant\PaymentController;
use App\Http\Controllers\Tenant\RoleController;
use App\Http\Controllers\Tenant\RoomController;
use App\Http\Controllers\Tenant\ScheduleSlotController;
use App\Http\Controllers\Tenant\StudentAttendanceController;
use App\Http\Controllers\Tenant\StudentAccountController;
use App\Http\Controllers\Tenant\StudentController;
use App\Http\Controllers\Tenant\StudentFeeController;
use App\Http\Controllers\Tenant\StudentGuardianController;
use App\Http\Controllers\Tenant\SubjectController;
use App\Http\Controllers\Tenant\TeacherController;
use App\Http\Controllers\Tenant\TermController;
use App\Http\Controllers\Tenant\TrackController;
use App\Http\Controllers\Tenant\UserController;
use App\Http\Controllers\Tenant\CashController;
use App\Http\Controllers\Tenant\ExpenseCategoryController;
use App\Http\Controllers\Tenant\ExpenseController;
use App\Http\Controllers\Tenant\PayrollAdjustmentController;
use App\Http\Controllers\Tenant\PayslipController;
use App\Http\Controllers\Tenant\ProfitLossController;
use App\Http\Controllers\Tenant\SalaryContractController;
use App\Http\Controllers\Tenant\StaffMemberController;
use App\Http\Controllers\Tenant\ExamController;
use App\Http\Controllers\Tenant\GradeController;
use App\Http\Controllers\Tenant\ResultsController;
use App\Http\Controllers\Tenant\SubjectCoefficientController;
use App\Http\Controllers\Tenant\TermRemarkController;
use App\Http\Controllers\Tenant\AlertController;
use App\Http\Controllers\Tenant\InboxController;
use App\Http\Controllers\Tenant\MessageController;
use App\Http\Controllers\Tenant\MessageTemplateController;
use App\Http\Controllers\Tenant\PortalAccountController;
use App\Http\Controllers\Tenant\PortalController;
use App\Http\Middleware\EnsureAnyPermission;
use App\Http\Middleware\EnsurePortalAccess;
use Illuminate\Support\Facades\Route;

/**
 * Registers index/show/store/update(/destroy) for a ResourceController with per-action permissions:
 * "<permission>.view" for reads, "<permission>.manage" for writes.
 */
$crud = static function (string $uri, string $controller, string $permission, bool $destroy = false): void {
    Route::get($uri, [$controller, 'index'])->middleware("can:{$permission}.view");
    Route::get("{$uri}/{id}", [$controller, 'show'])->whereNumber('id')->middleware("can:{$permission}.view");
    Route::post($uri, [$controller, 'store'])->middleware("can:{$permission}.manage");
    Route::patch("{$uri}/{id}", [$controller, 'update'])->whereNumber('id')->middleware("can:{$permission}.manage");

    if ($destroy) {
        Route::delete("{$uri}/{id}", [$controller, 'destroy'])->whereNumber('id')->middleware("can:{$permission}.manage");
    }
};

Route::prefix('v1')->group(function () use ($crud): void {

    /*
    |--------------------------------------------------------------------------
    | Platform (super-admin) — manages tenants. No tenant context here.
    |--------------------------------------------------------------------------
    */
    Route::prefix('platform')->group(function (): void {
        Route::post('auth/login', [PlatformAuthController::class, 'login'])->middleware('throttle:login');

        Route::middleware(['auth:sanctum', 'platform'])->group(function (): void {
            Route::get('auth/me', [PlatformAuthController::class, 'me']);
            Route::post('auth/logout', [PlatformAuthController::class, 'logout']);

            Route::apiResource('tenants', TenantController::class)->except('destroy');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Tenant (school / center) API — every route runs inside a resolved tenant.
    |--------------------------------------------------------------------------
    */
    Route::middleware('tenant')->group(function () use ($crud): void {
        Route::post('auth/login', [TenantAuthController::class, 'login'])->middleware('throttle:login');

        Route::middleware(['auth:sanctum', 'tenant.user'])->group(function () use ($crud): void {
            Route::get('auth/me', [TenantAuthController::class, 'me']);
            Route::post('auth/logout', [TenantAuthController::class, 'logout']);
            Route::post('auth/logout-all', [TenantAuthController::class, 'logoutAll']);

            // IAM
            Route::get('users', [UserController::class, 'index'])->middleware('can:users.view');
            Route::get('users/{user}', [UserController::class, 'show'])->whereNumber('user')->middleware('can:users.view');
            Route::post('users', [UserController::class, 'store'])->middleware('can:users.create');
            Route::patch('users/{user}', [UserController::class, 'update'])->whereNumber('user')->middleware('can:users.update');
            Route::get('roles', [RoleController::class, 'index'])->middleware('can:roles.view');
            Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('can:audit.view');

            // Academic structure
            $crud('academic-years', AcademicYearController::class, 'academic_years');
            Route::post('academic-years/{year}/terms', [TermController::class, 'store'])->whereNumber('year')
                ->middleware('can:academic_years.manage');
            Route::patch('terms/{id}', [TermController::class, 'update'])->whereNumber('id')
                ->middleware('can:academic_years.manage');

            $crud('levels', LevelController::class, 'levels', destroy: true);
            $crud('tracks', TrackController::class, 'levels', destroy: true);
            $crud('subjects', SubjectController::class, 'subjects', destroy: true);
            $crud('rooms', RoomController::class, 'rooms', destroy: true);
            $crud('groups', GroupController::class, 'groups', destroy: true);

            // People
            $crud('teachers', TeacherController::class, 'teachers', destroy: true);
            $crud('students', StudentController::class, 'students', destroy: true);
            $crud('guardians', GuardianController::class, 'guardians', destroy: true);
            Route::post('students/{student}/guardians', [StudentGuardianController::class, 'store'])->whereNumber('student')
                ->middleware('can:guardians.manage');
            Route::delete('students/{student}/guardians/{guardian}', [StudentGuardianController::class, 'destroy'])
                ->whereNumber(['student', 'guardian'])->middleware('can:guardians.manage');

            // Enrollments
            Route::get('enrollments', [EnrollmentController::class, 'index'])->middleware('can:enrollments.view');
            Route::post('enrollments', [EnrollmentController::class, 'store'])->middleware('can:enrollments.manage');
            Route::post('enrollments/{id}/transfer', [EnrollmentController::class, 'transfer'])->whereNumber('id')
                ->middleware('can:enrollments.manage');
            Route::post('enrollments/{id}/close', [EnrollmentController::class, 'close'])->whereNumber('id')
                ->middleware('can:enrollments.manage');

            // Timetable (recurring slots -> generated lessons) and attendance
            $anyOf = static fn (string ...$permissions): string => EnsureAnyPermission::class.':'.implode(',', $permissions);

            $crud('holidays', HolidayController::class, 'schedule', destroy: true);
            $crud('schedule-slots', ScheduleSlotController::class, 'schedule', destroy: true);

            Route::get('lessons', [LessonController::class, 'index'])->middleware($anyOf('schedule.view', 'teaching.own'));
            Route::post('lessons', [LessonController::class, 'store'])->middleware('can:schedule.manage');
            Route::post('lessons/generate', [LessonController::class, 'generate'])->middleware('can:schedule.manage');
            Route::get('lessons/{id}', [LessonController::class, 'show'])->whereNumber('id')
                ->middleware($anyOf('schedule.view', 'teaching.own'));
            Route::patch('lessons/{id}', [LessonController::class, 'update'])->whereNumber('id')->middleware('can:schedule.manage');
            Route::post('lessons/{id}/cancel', [LessonController::class, 'cancel'])->whereNumber('id')->middleware('can:schedule.manage');

            Route::get('lessons/{id}/attendance', [AttendanceController::class, 'show'])->whereNumber('id')
                ->middleware($anyOf('attendance.view', 'teaching.own'));
            Route::put('lessons/{id}/attendance', [AttendanceController::class, 'record'])->whereNumber('id')
                ->middleware($anyOf('attendance.record', 'teaching.own'));
            Route::get('absences', [AbsenceController::class, 'index'])->middleware('can:attendance.view');
            Route::patch('absences/{id}/justify', [AbsenceController::class, 'justify'])->whereNumber('id')
                ->middleware('can:attendance.record');
            Route::get('students/{student}/attendance-summary', [StudentAttendanceController::class, 'summary'])
                ->whereNumber('student')->middleware('can:attendance.view');

            // Finance (amounts are integer centimes of DZD)
            $crud('fee-plans', FeePlanController::class, 'fees');
            Route::post('fee-plans/{plan}/assign-group', [StudentFeeController::class, 'assignGroup'])->whereNumber('plan')
                ->middleware('can:fees.manage');
            $crud('student-fees', StudentFeeController::class, 'fees');

            Route::get('invoices', [InvoiceController::class, 'index'])->middleware('can:invoices.view');
            Route::get('invoices/{id}', [InvoiceController::class, 'show'])->whereNumber('id')->middleware('can:invoices.view');
            Route::post('invoices', [InvoiceController::class, 'store'])->middleware('can:invoices.manage');
            Route::post('invoices/generate', [InvoiceController::class, 'generate'])->middleware('can:invoices.manage');
            Route::post('invoices/{id}/void', [InvoiceController::class, 'void'])->whereNumber('id')->middleware('can:invoices.void');

            Route::get('payments', [PaymentController::class, 'index'])->middleware('can:payments.view');
            Route::get('payments/{id}', [PaymentController::class, 'show'])->whereNumber('id')->middleware('can:payments.view');
            Route::post('payments', [PaymentController::class, 'store'])->middleware('can:payments.record');
            Route::post('payments/{id}/reverse', [PaymentController::class, 'reverse'])->whereNumber('id')->middleware('can:payments.reverse');

            Route::get('students/{student}/account', [StudentAccountController::class, 'show'])->whereNumber('student')
                ->middleware('can:invoices.view');
            Route::post('students/{student}/allocate-credit', [StudentAccountController::class, 'allocateCredit'])
                ->whereNumber('student')->middleware('can:payments.record');

            Route::get('reports/finance/summary', [FinanceReportController::class, 'summary'])->middleware('can:finance.reports');
            Route::get('reports/finance/receivables', [FinanceReportController::class, 'receivables'])->middleware('can:finance.reports');

            // Expenses, payroll and cash register (amounts are integer centimes of DZD)
            $crud('expense-categories', ExpenseCategoryController::class, 'expenses', destroy: true);
            Route::get('expenses', [ExpenseController::class, 'index'])->middleware('can:expenses.view');
            Route::get('expenses/{id}', [ExpenseController::class, 'show'])->whereNumber('id')->middleware('can:expenses.view');
            Route::post('expenses', [ExpenseController::class, 'store'])->middleware('can:expenses.manage');
            Route::post('expenses/{id}/reverse', [ExpenseController::class, 'reverse'])->whereNumber('id')->middleware('can:expenses.reverse');

            $crud('staff', StaffMemberController::class, 'payroll');
            $crud('salary-contracts', SalaryContractController::class, 'payroll');
            Route::get('payroll-adjustments', [PayrollAdjustmentController::class, 'index'])->middleware('can:payroll.view');
            Route::post('payroll-adjustments', [PayrollAdjustmentController::class, 'store'])->middleware('can:payroll.manage');
            Route::post('payroll-adjustments/{id}/cancel', [PayrollAdjustmentController::class, 'cancel'])->whereNumber('id')
                ->middleware('can:payroll.manage');

            Route::get('payslips', [PayslipController::class, 'index'])->middleware('can:payroll.view');
            Route::get('payslips/{id}', [PayslipController::class, 'show'])->whereNumber('id')->middleware('can:payroll.view');
            Route::post('payslips/generate', [PayslipController::class, 'generate'])->middleware('can:payroll.manage');
            Route::post('payslips/{id}/pay', [PayslipController::class, 'pay'])->whereNumber('id')->middleware('can:payroll.pay');
            Route::post('payslips/{id}/void', [PayslipController::class, 'void'])->whereNumber('id')->middleware('can:payroll.manage');
            Route::post('payslips/{id}/reverse', [PayslipController::class, 'reverse'])->whereNumber('id')->middleware('can:payroll.reverse');

            Route::get('cash/position', [CashController::class, 'position'])->middleware('can:cash.view');
            Route::get('cash/closings', [CashController::class, 'closings'])->middleware('can:cash.view');
            Route::post('cash/closings', [CashController::class, 'close'])->middleware('can:cash.close');

            Route::get('reports/finance/profit-loss', ProfitLossController::class)->middleware('can:finance.reports');

            // Exams, grades and results. A teacher (teaching.own) reaches only their own exams.
            Route::get('exams', [ExamController::class, 'index'])->middleware($anyOf('exams.view', 'teaching.own'));
            Route::get('exams/{id}', [ExamController::class, 'show'])->whereNumber('id')->middleware($anyOf('exams.view', 'teaching.own'));
            Route::post('exams', [ExamController::class, 'store'])->middleware($anyOf('exams.manage', 'teaching.own'));
            Route::patch('exams/{id}', [ExamController::class, 'update'])->whereNumber('id')->middleware($anyOf('exams.manage', 'teaching.own'));
            Route::delete('exams/{id}', [ExamController::class, 'destroy'])->whereNumber('id')->middleware($anyOf('exams.manage', 'teaching.own'));
            Route::post('exams/{id}/lock', [ExamController::class, 'lock'])->whereNumber('id')->middleware('can:exams.manage');
            Route::post('exams/{id}/unlock', [ExamController::class, 'unlock'])->whereNumber('id')->middleware('can:exams.manage');
            Route::get('exams/{id}/grades', [GradeController::class, 'show'])->whereNumber('id')->middleware($anyOf('exams.view', 'teaching.own'));
            Route::put('exams/{id}/grades', [GradeController::class, 'record'])->whereNumber('id')->middleware($anyOf('grades.record', 'teaching.own'));

            $crud('subject-coefficients', SubjectCoefficientController::class, 'exams', destroy: true);
            Route::put('students/{student}/term-remarks/{term}', [TermRemarkController::class, 'upsert'])
                ->whereNumber(['student', 'term'])->middleware('can:exams.manage');
            Route::get('groups/{group}/results', [ResultsController::class, 'group'])->whereNumber('group')->middleware('can:results.view');
            Route::get('students/{student}/report-card', [ResultsController::class, 'reportCard'])->whereNumber('student')
                ->middleware('can:results.view');

            // Messages: templates, outbox, alerts and the user's own inbox (no permission: own rows only)
            $crud('message-templates', MessageTemplateController::class, 'templates', destroy: true);
            Route::get('messages', [MessageController::class, 'index'])->middleware('can:messages.view');
            Route::get('messages/{id}', [MessageController::class, 'show'])->whereNumber('id')->middleware('can:messages.view');
            Route::post('messages', [MessageController::class, 'store'])->middleware(['can:messages.send', 'throttle:20,1']);
            Route::post('messages/{id}/cancel', [MessageController::class, 'cancel'])->whereNumber('id')->middleware('can:messages.send');
            Route::post('messages/{id}/retry', [MessageController::class, 'retry'])->whereNumber('id')->middleware('can:messages.send');

            Route::post('alerts/absences/run', [AlertController::class, 'absences'])->middleware(['can:alerts.run', 'throttle:10,1']);
            Route::post('alerts/payments/run', [AlertController::class, 'payments'])->middleware(['can:alerts.run', 'throttle:10,1']);

            // Portal accounts (staff side) and the parent / student portal (read-only, own children only)
            Route::get('portal-accounts/{type}/{id}', [PortalAccountController::class, 'show'])
                ->whereIn('type', ['guardians', 'students'])->whereNumber('id')->middleware('can:portal.manage');
            Route::post('portal-accounts/{type}/{id}', [PortalAccountController::class, 'store'])
                ->whereIn('type', ['guardians', 'students'])->whereNumber('id')->middleware(['can:portal.manage', 'throttle:20,1']);
            Route::patch('portal-accounts/{type}/{id}', [PortalAccountController::class, 'update'])
                ->whereIn('type', ['guardians', 'students'])->whereNumber('id')->middleware('can:portal.manage');

            Route::prefix('portal')->middleware(EnsurePortalAccess::class)->group(function (): void {
                Route::get('students', [PortalController::class, 'students']);
                Route::prefix('students/{student}')->where(['student' => '[0-9]+'])->group(function (): void {
                    Route::get('/', [PortalController::class, 'student']);
                    Route::get('absences', [PortalController::class, 'absences']);
                    Route::get('lessons', [PortalController::class, 'lessons']);
                    Route::get('attendance-summary', [StudentAttendanceController::class, 'summary']);
                    Route::get('report-card', [ResultsController::class, 'reportCard']);
                    Route::middleware(EnsurePortalAccess::class.':finance')->group(function (): void {
                        Route::get('account', [StudentAccountController::class, 'show']);
                        Route::get('invoices', [PortalController::class, 'invoices']);
                        Route::get('payments', [PortalController::class, 'payments']);
                    });
                });
            });

            Route::get('inbox', [InboxController::class, 'index']);
            Route::get('inbox/unread-count', [InboxController::class, 'unreadCount']);
            Route::post('inbox/read-all', [InboxController::class, 'markAllRead']);
            Route::post('inbox/{id}/read', [InboxController::class, 'markRead'])->whereNumber('id');
        });
    });
});
