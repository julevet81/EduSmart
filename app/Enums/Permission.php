<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Single source of truth for permission names.
 * Convention: "<resource>.view" for reads, "<resource>.manage" for create/update.
 */
enum Permission: string
{
    // IAM
    case UsersView = 'users.view';
    case UsersCreate = 'users.create';
    case UsersUpdate = 'users.update';
    case RolesView = 'roles.view';
    case RolesManage = 'roles.manage';
    case AuditView = 'audit.view';
    case SettingsView = 'settings.view';
    case SettingsUpdate = 'settings.update';

    // Academic core
    case AcademicYearsView = 'academic_years.view';
    case AcademicYearsManage = 'academic_years.manage';
    case LevelsView = 'levels.view';
    case LevelsManage = 'levels.manage';
    case SubjectsView = 'subjects.view';
    case SubjectsManage = 'subjects.manage';
    case RoomsView = 'rooms.view';
    case RoomsManage = 'rooms.manage';
    case GroupsView = 'groups.view';
    case GroupsManage = 'groups.manage';
    case TeachersView = 'teachers.view';
    case TeachersManage = 'teachers.manage';
    case StudentsView = 'students.view';
    case StudentsManage = 'students.manage';
    case StudentsViewMedical = 'students.view_medical';
    case GuardiansView = 'guardians.view';
    case GuardiansManage = 'guardians.manage';
    case EnrollmentsView = 'enrollments.view';
    case EnrollmentsManage = 'enrollments.manage';

    // Timetable and attendance
    case ScheduleView = 'schedule.view';
    case ScheduleManage = 'schedule.manage';
    case AttendanceView = 'attendance.view';
    case AttendanceRecord = 'attendance.record';
    /** Teachers: see and take attendance of THEIR OWN lessons only (row-level rule in TeacherScope). */
    case TeachingOwn = 'teaching.own';

    // Finance
    case FeesView = 'fees.view';
    case FeesManage = 'fees.manage';
    case InvoicesView = 'invoices.view';
    case InvoicesManage = 'invoices.manage';
    /** Voiding an invoice removes money owed: kept apart from day-to-day invoicing. */
    case InvoicesVoid = 'invoices.void';
    case PaymentsView = 'payments.view';
    case PaymentsRecord = 'payments.record';
    /** Reversing a payment removes money received: owner/manager only by default. */
    case PaymentsReverse = 'payments.reverse';
    case FinanceReports = 'finance.reports';

    // Expenses, payroll and cash register
    case ExpensesView = 'expenses.view';
    case ExpensesManage = 'expenses.manage';
    case ExpensesReverse = 'expenses.reverse';
    case PayrollView = 'payroll.view';
    /** Staff, contracts, adjustments (advances/deductions/bonuses) and generating payslips. */
    case PayrollManage = 'payroll.manage';
    case PayrollPay = 'payroll.pay';
    case PayrollReverse = 'payroll.reverse';
    case CashView = 'cash.view';
    case CashClose = 'cash.close';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $p): string => $p->value, self::cases());
    }
}
