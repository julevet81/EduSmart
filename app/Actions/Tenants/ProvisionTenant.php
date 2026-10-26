<?php

declare(strict_types=1);

namespace App\Actions\Tenants;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Curriculum\AlgerianCurriculum;
use App\Support\Rbac\RoleBlueprint;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/** Creates a tenant with its default roles, Algerian curriculum reference data and owner account, atomically. */
final class ProvisionTenant
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(NewTenantData $data): ProvisionedTenant
    {
        return DB::transaction(function () use ($data): ProvisionedTenant {
            $tenant = Tenant::query()->create([
                'name' => $data->name,
                'slug' => $data->slug,
                'type' => $data->type,
                'status' => TenantStatus::Trial,
                'contact_email' => $data->contactEmail,
                'phone' => $data->phone,
                'trial_ends_at' => now()->addDays((int) config('saas.trial_days')),
            ]);

            return $this->context->run($tenant, function () use ($tenant, $data): ProvisionedTenant {
                RoleBlueprint::syncFor($tenant);
                AlgerianCurriculum::seedFor($tenant);

                $owner = User::query()->create([
                    'name' => $data->ownerName,
                    'email' => $data->ownerEmail,
                    'password' => $data->ownerPassword,
                    'is_active' => true,
                ]);
                $owner->assignRole(RoleBlueprint::OWNER);

                return new ProvisionedTenant($tenant, $owner);
            });
        });
    }
}
