<?php

declare(strict_types=1);

namespace App\Support\Rbac;

use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Spatie\Permission\Models\Role;

/**
 * Prevents privilege escalation inside a tenant:
 *  - nobody can hand out a role holding permissions they do not have themselves,
 *  - nobody can modify a user who holds permissions the actor lacks,
 *  - an organization always keeps at least one active owner.
 */
final class RoleGuard
{
    public function __construct(private readonly TenantContext $tenant) {}

    /** @param  list<string>  $roleNames */
    public function assertCanAssign(User $actor, array $roleNames): void
    {
        if ($roleNames === []) {
            return;
        }

        $actorPermissions = $actor->getAllPermissions()->pluck('name');

        $roles = Role::query()
            ->where('tenant_id', $this->tenant->id())
            ->where('guard_name', RoleBlueprint::GUARD)
            ->whereIn('name', $roleNames)
            ->with('permissions')
            ->get();

        foreach ($roles as $role) {
            if ($role->permissions->pluck('name')->diff($actorPermissions)->isNotEmpty()) {
                abort(403, "You cannot assign the role '{$role->name}'.");
            }
        }
    }

    public function assertCanManage(User $actor, User $target): void
    {
        if ($actor->is($target)) {
            return;
        }

        if ($target->getAllPermissions()->pluck('name')->diff($actor->getAllPermissions()->pluck('name'))->isNotEmpty()) {
            abort(403, 'You cannot modify a user with higher privileges.');
        }
    }

    /** @param  list<string>|null  $newRoles  null = roles unchanged */
    public function assertOwnerRetained(User $target, ?array $newRoles, ?bool $newActive): void
    {
        if (! $target->hasRole(RoleBlueprint::OWNER)) {
            return;
        }

        $stillOwner = $newRoles === null || in_array(RoleBlueprint::OWNER, $newRoles, true);
        $stillActive = $newActive ?? $target->is_active;

        if ($stillOwner && $stillActive) {
            return;
        }

        $otherOwnerExists = User::query()
            ->role(RoleBlueprint::OWNER)
            ->where('is_active', true)
            ->whereKeyNot($target->getKey())
            ->exists();

        if (! $otherOwnerExists) {
            abort(422, 'The organization must keep at least one active owner.');
        }
    }
}
