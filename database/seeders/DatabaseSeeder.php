<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Intentionally empty: platform admins are created with `php artisan platform:create-admin`,
     * and tenants through the platform API (which provisions roles and the owner account).
     */
    public function run(): void {}
}
