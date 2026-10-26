<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * tenant_id is filled automatically from the current tenant context:
 *   app(TenantContext::class)->run($tenant, fn () => User::factory()->create());
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'Str0ng-Passw0rd!',
            'is_active' => true,
        ];
    }
}
