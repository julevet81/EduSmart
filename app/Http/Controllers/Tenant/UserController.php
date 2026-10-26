<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Rbac\RoleGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class UserController extends Controller
{
    public function __construct(private readonly RoleGuard $guard) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'q' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $users = User::query()
            ->with('roles')
            ->when($request->query('q'), function ($q, string $term): void {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('email', 'like', $like));
            })
            ->orderBy('id')
            ->cursorPaginate((int) $request->query('per_page', 25));

        return UserResource::collection($users);
    }

    public function show(User $user): UserResource
    {
        return new UserResource($user->load('roles'));
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $roles = $request->validated('roles', []);

        $this->guard->assertCanAssign($actor, $roles);

        $user = DB::transaction(function () use ($request, $roles): User {
            $user = User::query()->create(Arr::except($request->validated(), ['roles']));
            $user->syncRoles($roles);

            return $user;
        });

        return (new UserResource($user->load('roles')))->response()->setStatusCode(201);
    }

    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        /** @var User $actor */
        $actor = $request->user();
        $data = $request->validated();
        $roles = array_key_exists('roles', $data) ? $data['roles'] : null;
        $active = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : null;

        $this->guard->assertCanManage($actor, $user);

        if ($roles !== null) {
            $this->guard->assertCanAssign($actor, $roles);
        }

        if ($active === false && $actor->is($user)) {
            abort(422, 'You cannot deactivate your own account.');
        }

        $this->guard->assertOwnerRetained($user, $roles, $active);

        DB::transaction(function () use ($user, $data, $roles, $active): void {
            $user->update(Arr::except($data, ['roles']));

            if ($roles !== null) {
                $before = $user->roles->pluck('name')->all();
                $user->syncRoles($roles);

                AuditRecorder::event('user.roles_synced', [
                    'user_id' => $user->getKey(),
                    'before' => $before,
                    'after' => $roles,
                ]);
            }

            if ($active === false) {
                $user->tokens()->delete();
            }
        });

        return new UserResource($user->refresh()->load('roles'));
    }
}
