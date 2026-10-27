<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use App\Support\Validation\TenantRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

/** Login accounts of guardians ("parent" role) and students ("student" role), linked through profile.user_id. */
final class PortalAccountController extends Controller
{
    private const ROLES = ['guardians' => 'parent', 'students' => 'student'];

    public function show(string $type, string $id): JsonResponse
    {
        $profile = $this->profile($type, $id);
        abort_if($profile->user_id === null, 404, 'This person has no portal account.');

        return response()->json(['data' => $this->present($profile, User::query()->findOrFail($profile->user_id))]);
    }

    public function store(Request $request, string $type, string $id): JsonResponse
    {
        $profile = $this->profile($type, $id);
        abort_if($profile->user_id !== null, 422, 'This person already has a portal account.');

        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255', TenantRules::unique('users', 'email')],
            'password' => ['required', 'string', Password::defaults()],
        ]);

        $user = DB::transaction(function () use ($data, $profile, $type): User {
            $user = User::query()->create([
                'name' => trim($profile->first_name.' '.$profile->last_name),
                'email' => $data['email'],
                'password' => $data['password'],
                'is_active' => true,
            ]);
            $user->syncRoles([self::ROLES[$type]]);
            $profile->update(['user_id' => $user->getKey()]);

            return $user;
        });

        return response()->json(['data' => $this->present($profile, $user)], 201);
    }

    /** Resets the password and/or enables or disables the account. Either change signs the person out everywhere. */
    public function update(Request $request, string $type, string $id): JsonResponse
    {
        $profile = $this->profile($type, $id);
        abort_if($profile->user_id === null, 404, 'This person has no portal account.');

        $data = $request->validate([
            'password' => ['sometimes', 'string', Password::defaults()],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        abort_if($data === [], 422, 'Send a new password or an is_active value.');

        $user = User::query()->findOrFail($profile->user_id);

        DB::transaction(function () use ($user, $data): void {
            $user->update($data);
            $user->tokens()->delete();
        });

        return response()->json(['data' => $this->present($profile, $user->refresh())]);
    }

    private function profile(string $type, string $id): Guardian|Student
    {
        return $type === 'guardians' ? Guardian::query()->findOrFail($id) : Student::query()->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function present(Guardian|Student $profile, User $user): array
    {
        return [
            'user_id' => $user->getKey(),
            'profile_id' => $profile->getKey(),
            'email' => $user->email,
            'is_active' => $user->is_active,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
        ];
    }
}
