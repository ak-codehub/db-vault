<?php

declare(strict_types=1);

namespace DbVault\Http\Controllers\Api;

use DbVault\Http\Controllers\Controller;
use DbVault\Http\Resources\UserResource;
use DbVault\Models\Role;
use DbVault\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Admin-only user management. Onboarding a new vault user is: create the
 * account here with a temporary password and one or more roles, then hand
 * the credentials to the person out-of-band. The seat is active immediately
 * and the user signs in at {path}/login.
 *
 * Guarded by 'vault.role:admin' at the route layer. There is deliberately no
 * self-service signup — the vault is invite-only by an existing admin.
 */
class UserController extends Controller
{
    private const ASSIGNABLE_ROLES = ['developer', 'approver', 'admin', 'auditor'];

    public function index(): JsonResponse
    {
        $users = User::query()
            ->with('roles')
            ->orderBy('name')
            ->get();

        return response()->json([
            'users' => UserResource::collection($users)->resolve(),
            'roles' => self::ASSIGNABLE_ROLES,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            // The vault stores users on its own connection (config
            // 'dbvault.connection'), which is NOT Laravel's default. Qualify
            // the unique rule with that connection, or it queries the host's
            // default database where vault_users doesn't exist.
            'email' => ['required', 'email', 'max:190', $this->uniqueVaultEmail()],
            'password' => ['required', 'string', Password::min(12)],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::in(self::ASSIGNABLE_ROLES)],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'is_active' => true,
        ]);

        $user->roles()->sync($this->roleIds($validated['roles']));

        return response()->json([
            'user' => (new UserResource($user->load('roles')))->resolve(),
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'roles' => ['sometimes', 'required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::in(self::ASSIGNABLE_ROLES)],
            'is_active' => ['sometimes', 'boolean'],
            'password' => ['sometimes', 'required', 'string', Password::min(12)],
        ]);

        // Guard against an admin locking themselves out or stripping their
        // own admin role mid-session.
        if ($this->isSelf($user)) {
            if (array_key_exists('is_active', $validated) && $validated['is_active'] === false) {
                return response()->json(['message' => 'You cannot deactivate your own account.'], 422);
            }
            if (array_key_exists('roles', $validated) && ! in_array('admin', $validated['roles'], true)) {
                return response()->json(['message' => 'You cannot remove your own admin role.'], 422);
            }
        }

        $user->fill(array_filter(
            [
                'name' => $validated['name'] ?? null,
                'password' => $validated['password'] ?? null,
            ],
            static fn ($v) => $v !== null,
        ));

        if (array_key_exists('is_active', $validated)) {
            $user->is_active = $validated['is_active'];
        }

        $user->save();

        if (array_key_exists('roles', $validated)) {
            $user->roles()->sync($this->roleIds($validated['roles']));
        }

        return response()->json([
            'user' => (new UserResource($user->load('roles')))->resolve(),
        ]);
    }

    /**
     * A unique-email rule bound to the vault's own connection so it queries
     * the database the vault_users table actually lives in (not the host's
     * default connection). Uses the "connection.table" form the validator
     * understands.
     */
    private function uniqueVaultEmail(): \Illuminate\Validation\Rules\Unique
    {
        $connection = config('dbvault.connection');
        $table = $connection ? "{$connection}.vault_users" : 'vault_users';

        return Rule::unique($table, 'email');
    }

    /**
     * @param  list<string>  $names
     * @return list<int>
     */
    private function roleIds(array $names): array
    {
        return Role::whereIn('name', $names)->pluck('id')->all();
    }

    private function isSelf(User $user): bool
    {
        return (int) $user->id === (int) Auth::guard(config('dbvault.guard', 'vault'))->id();
    }
}
