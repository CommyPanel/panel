<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Auth\RevokeSessions;
use App\Auth\UserAccess;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\I18n\Locales;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function __construct(private readonly UserAccess $access) {}

    public function index(Request $request): Response
    {
        $actor = $request->user();

        return Inertia::render('users/index', [
            'users' => User::query()->with('roles:id,name')->orderBy('id')->get()
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'is_super' => $user->isSuper(),
                    'is_you' => $user->is($actor),
                    'role' => $user->getRoleNames()->first(),
                    'two_factor' => $user->two_factor_confirmed_at !== null,
                    'created_at' => $user->created_at?->toIso8601String(),
                    'can_edit' => $actor !== null && $this->access->canManage($actor, $user),
                    'can_delete' => $actor !== null && ! $user->is($actor) && $this->access->canManage($actor, $user),
                ])->values(),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('users/form', ['user' => null, 'roles' => $this->roles($request->user())]);
    }

    public function store(StoreUserRequest $request, AuditLogger $audit): RedirectResponse
    {
        $role = $this->roleFrom($request->validated('role'), $request->user());

        $user = User::query()->create([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'password' => $request->validated('password'),
        ]);
        $user->forceFill(['email_verified_at' => now(), 'locale' => Locales::resolve($request->validated('locale') ?? $request->user()->locale)])->save();

        if ($role !== null) {
            $user->syncRoles([$role]);
        }

        $audit->record('user.created', null, ['user' => $user->email, 'role' => $role?->name], $user);

        return redirect()->route('users.index')->with('status', "{$user->name} was added.");
    }

    public function edit(Request $request, User $user): Response
    {
        $this->guard($request->user(), $user);

        return Inertia::render('users/form', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'locale' => Locales::resolve($user->locale),
                'email' => $user->email,
                'role_id' => Role::query()->where('guard_name', 'web')->whereIn('name', $user->getRoleNames())->value('id'),
                'is_you' => $user->is($request->user()),
            ],
            'roles' => $this->roles($request->user()),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user, AuditLogger $audit, RevokeSessions $revoke): RedirectResponse
    {
        $actor = $request->user();
        $this->guard($actor, $user);

        $role = $this->roleFrom($request->validated('role'), $actor, $user);
        $before = $user->getRoleNames()->first();

        $user->fill($request->safe()->only(['name', 'email']));

        if ($request->filled('locale')) {
            $user->forceFill(['locale' => Locales::resolve($request->validated('locale'))]);
        }
        $passwordChanged = filled($request->validated('password'));

        if ($passwordChanged) {
            // A new password ends every session of that account, so a stolen one stops working.
            $user->forceFill(['password' => $request->validated('password'), 'remember_token' => null]);
        }

        $user->save();
        $user->syncRoles($role === null ? [] : [$role]);

        if ($passwordChanged && ! $user->is($actor)) {
            $revoke($user);
        }

        $audit->record('user.updated', null, [
            'user' => $user->email,
            'role_from' => $before,
            'role_to' => $role?->name,
            'password_changed' => $passwordChanged,
        ], $user);

        return redirect()->route('users.index')->with('status', "{$user->name} was updated.");
    }

    public function destroy(Request $request, User $user, AuditLogger $audit, RevokeSessions $revoke): RedirectResponse
    {
        $actor = $request->user();
        $this->guard($actor, $user);

        if ($user->is($actor)) {
            return back()->withErrors(['user' => 'You cannot delete your own account.']);
        }

        $email = $user->email;
        $revoke($user);
        $user->delete();

        $audit->record('user.deleted', null, ['user' => $email]);

        return redirect()->route('users.index')->with('status', "{$email} was deleted.");
    }

    /**
     * Nobody changes the super user, and nobody changes an account with more access than their own.
     */
    private function guard(?User $actor, User $target): void
    {
        abort_if($target->isSuper(), 403, 'The super user cannot be changed here. Use the admin:reset command on the server.');
        abort_if($actor === null || ! $this->access->canManage($actor, $target), 403, 'That account has more access than you do.');
    }

    /**
     * @throws ValidationException
     */
    private function roleFrom(mixed $id, ?User $actor, ?User $editing = null): ?Role
    {
        if ($id === null || $id === '') {
            return $editing !== null && $editing->is($actor) && $editing->getRoleNames()->isNotEmpty()
                ? throw ValidationException::withMessages(['role' => 'You cannot remove your own role.'])
                : null;
        }

        $role = Role::query()->where('guard_name', 'web')->whereKey($id)->firstOrFail();

        if ($editing !== null && $editing->is($actor) && ! $editing->hasRole($role)) {
            throw ValidationException::withMessages(['role' => 'You cannot change your own role.']);
        }

        // Keeping the role someone already has is always fine; handing out a new one is not if it exceeds your own access.
        $keeps = $editing?->hasRole($role) ?? false;

        if (! $keeps && ($actor === null || ! $this->access->canHandOutRole($actor, $role))) {
            throw ValidationException::withMessages(['role' => 'That role grants more than you have yourself, so you cannot give it to someone.']);
        }

        return $role;
    }

    /**
     * @return list<array{id: int, name: string, permissions: int, assignable: bool}>
     */
    private function roles(?User $actor): array
    {
        return array_values(Role::query()->where('guard_name', 'web')->with('permissions:id,name')->orderBy('name')->get()
            ->map(fn (Role $role) => [
                'id' => (int) $role->id,
                'name' => $role->name,
                'permissions' => $role->permissions->count(),
                'assignable' => $actor !== null && $this->access->canHandOutRole($actor, $role),
            ])->all());
    }
}
