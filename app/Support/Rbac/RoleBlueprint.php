<?php

declare(strict_types=1);

namespace App\Support\Rbac;

use App\Enums\Permission;
use App\Models\Tenant;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Default roles created for every new tenant. Tenants can later customize them.
 * syncFor() is ADDITIVE: it creates missing roles/permissions and grants new permissions,
 * but never revokes what a tenant changed. Safe to re-run after every deployment.
 */
final class RoleBlueprint
{
    public const OWNER = 'owner';

    public const GUARD = 'web';

    /** @return array<string, list<Permission>> */
    public static function definitions(): array
    {
        $views = array_values(array_filter(
            Permission::cases(),
            static fn (Permission $p): bool => str_ends_with($p->value, '.view'),
        ));

        return [
            self::OWNER => Permission::cases(),

            'manager' => array_values(array_filter(
                Permission::cases(),
                static fn (Permission $p): bool => ! in_array($p, [Permission::RolesManage, Permission::SettingsUpdate], true),
            )),

            'secretary' => [
                ...array_filter($views, static fn (Permission $p): bool => ! in_array($p, [
                    Permission::UsersView, Permission::RolesView, Permission::AuditView, Permission::SettingsView,
                    Permission::StudentsViewMedical, Permission::FeesView,
                    Permission::ExpensesView, Permission::PayrollView, Permission::CashView,
                ], true)),
                Permission::StudentsManage, Permission::GuardiansManage, Permission::EnrollmentsManage,
                Permission::ScheduleManage, Permission::AttendanceRecord, Permission::PaymentsRecord,
            ],

            'accountant' => [
                Permission::StudentsView, Permission::GuardiansView, Permission::EnrollmentsView,
                Permission::GroupsView, Permission::LevelsView, Permission::AcademicYearsView,
                Permission::SubjectsView, Permission::AttendanceView,
                Permission::FeesView, Permission::FeesManage, Permission::InvoicesView, Permission::InvoicesManage,
                Permission::PaymentsView, Permission::PaymentsRecord, Permission::FinanceReports,
                Permission::ExpensesView, Permission::ExpensesManage, Permission::PayrollView, Permission::PayrollManage,
                Permission::PayrollPay, Permission::CashView, Permission::CashClose,
            ],

            // Teachers only see and take attendance of their own lessons (TeacherScope), nothing else.
            'teacher' => [Permission::TeachingOwn],
            // Parent/student portals use row-level access (own children / own record), added with the portal.
            'parent' => [],
            'student' => [],
        ];
    }

    public static function syncFor(Tenant $tenant): void
    {
        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($tenant->getKey());

        try {
            foreach (Permission::values() as $name) {
                PermissionModel::findOrCreate($name, self::GUARD);
            }

            foreach (self::definitions() as $roleName => $permissions) {
                $role = Role::findOrCreate($roleName, self::GUARD);

                if ($permissions !== []) {
                    $role->givePermissionTo(array_map(static fn (Permission $p): string => $p->value, $permissions));
                }
            }
        } finally {
            $registrar->setPermissionsTeamId($previousTeam);
            $registrar->forgetCachedPermissions();
        }
    }
}
