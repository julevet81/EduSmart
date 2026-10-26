<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PlatformAdmin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

final class CreatePlatformAdmin extends Command
{
    protected $signature = 'platform:create-admin {email} {name}';

    protected $description = 'Create a platform (super) administrator';

    public function handle(): int
    {
        $password = (string) $this->secret('Password (min 12 chars, mixed case, numbers, symbols)');

        $validator = Validator::make(
            ['email' => $this->argument('email'), 'name' => $this->argument('name'), 'password' => $password],
            [
                'email' => ['required', 'email', 'unique:platform_admins,email'],
                'name' => ['required', 'string', 'max:255'],
                'password' => ['required', Password::min(12)->letters()->mixedCase()->numbers()->symbols()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        PlatformAdmin::query()->create([
            'name' => $this->argument('name'),
            'email' => $this->argument('email'),
            'password' => $password,
            'is_active' => true,
        ]);

        $this->info('Platform admin created.');

        return self::SUCCESS;
    }
}
