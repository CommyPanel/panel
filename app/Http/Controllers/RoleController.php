<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Auth\Permissions;
use App\Auth\SyncRolesAndPermissions;
use App\Auth\UserAccess;
use App\Http\Requests\RoleRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function __construct(private readonly UserAccess $access) {}

    public function index(Request $request): Response
    {
        $actor = $request->user();

        return Inertia::render('roles/index', [
            'roles' => Role::query()->where('guard_name', 'web')->withCount(['users', 'permissions'])->orderBy('name')->get()
                ->map(fn (Role $role) => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'permissions' => $role->permissions_count,
                    'users' => $role->users_count,
                    'locked' => $role->name === SyncRolesAndPermissions::ADMIN_ROLE,
                    'can_edit' => $actor !== null && $this->canEdit($actor, $role),
                ])->values(),
            'total_permissions' => count(Permissions::names()),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('roles/form', [
            'role' => null,
            'groups' => $this->groups($request->user()),
        ]);
    }

    public function store(RoleRequest $request, AuditLogger $audit): RedirectResponse
    {
        $actor = $request->user();
        $permissions = $this->checkedPermissions($request, $actor, current: []);

        $role = Role::query()->create(['name' => $request->validated('name'), 'guard_name' => 'web']);
        $role->syncPermissions($permissions);

        $audit->record('role.created', null, ['role' => $role->name, 'permissions' => $permissions]);

        return redirect()->route('roles.index')->with('status', __('The role :name was created.', ['name' => $role->name]));
    }

    public function edit(Request $request, Role $role): Response
    {
        $this->authorizeEdit($request->user(), $role);

        return Inertia::render('roles/form', [
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'locked' => $role->name === SyncRolesAndPermissions::ADMIN_ROLE,
                'permissions' => $role->permissions->pluck('name')->values(),
                'users' => $role->users()->count(),
            ],
            'groups' => $this->groups($request->user()),
        ]);
    }

    public function update(RoleRequest $request, Role $role, AuditLogger $audit): RedirectResponse
    {
        $actor = $request->user();
        $this->authorizeEdit($actor, $role);

        $current = array_values(array_map('strval', $role->permissions->pluck('name')->all()));
        $permissions = $this->checkedPermissions($request, $actor, $current);
        $before = $role->name;

        $role->update(['name' => $request->validated('name')]);
        $role->syncPermissions($permissions);

        $audit->record('role.updated', null, [
            'role' => $role->name,
            'renamed_from' => $before === $role->name ? null : $before,
            'added' => array_values(array_diff($permissions, $current)),
            'removed' => array_values(array_diff($current, $permissions)),
        ]);

        return redirect()->route('roles.index')->with('status', __('The role :name was saved.', ['name' => $role->name]));
    }

    public function destroy(Request $request, Role $role, AuditLogger $audit): RedirectResponse
    {
        $this->authorizeEdit($request->user(), $role);

        $users = $role->users()->count();

        if ($users > 0) {
            return back()->withErrors(['role' => $users === 1
                ? __(':role is assigned to :count user. Give them another role first.', ['role' => $role->name, 'count' => $users])
                : __(':role is assigned to :count users. Give them another role first.', ['role' => $role->name, 'count' => $users])]);
        }

        $name = $role->name;
        $role->delete();
        $audit->record('role.deleted', null, ['role' => $name]);

        return redirect()->route('roles.index')->with('status', __('The role :name was deleted.', ['name' => $name]));
    }

    /**
     * The "admin" role always holds every permission, so it is never edited or deleted;
     * and nobody edits a role that grants more than they hold themselves.
     */
    private function canEdit(User $actor, Role $role): bool
    {
        return $role->name !== SyncRolesAndPermissions::ADMIN_ROLE && $this->access->canHandOutRole($actor, $role);
    }

    private function authorizeEdit(?User $actor, Role $role): void
    {
        abort_if($role->name === SyncRolesAndPermissions::ADMIN_ROLE, 403, __('The admin role always has every permission and cannot be changed.'));
        abort_if($actor === null || ! $this->access->canHandOutRole($actor, $role), 403, __('That role grants more than you have yourself.'));
    }

    /**
     * The submitted permissions, after making sure none of the newly added ones is beyond the actor.
     *
     * @param  list<string>  $current
     * @return list<string>
     */
    private function checkedPermissions(RoleRequest $request, ?User $actor, array $current): array
    {
        /** @var list<string> $wanted */
        $wanted = array_values(array_unique($request->validated('permissions') ?? []));
        $added = array_diff($wanted, $current);

        abort_if($actor === null || ! $this->access->holdsAll($actor, $added), 403, __('You can only grant permissions you have yourself.'));

        return $wanted;
    }

    /**
     * Permissions grouped for the form; the ones the actor lacks are marked so they cannot be ticked.
     *
     * @return list<array{group: string, permissions: list<array{name: string, label: string, description: string, grantable: bool}>}>
     */
    private function groups(?User $actor): array
    {
        $held = $actor === null ? [] : $this->access->permissionsOf($actor);
        $groups = [];

        foreach (Permissions::definitions() as $name => $definition) {
            $groups[$definition['group']][] = [
                'name' => $name,
                'label' => $definition['label'],
                'description' => $definition['description'],
                'grantable' => in_array($name, $held, true),
            ];
        }

        return array_map(fn (string $group, array $permissions) => ['group' => $group, 'permissions' => $permissions], array_keys($groups), $groups);
    }
}
