<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Http\Controllers\Admin;

use App\Modules\IdentityAccess\Actions\DeleteRole;
use App\Modules\IdentityAccess\Actions\SaveRole;
use App\Modules\IdentityAccess\Http\Requests\RoleRequest;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\IdentityAccess\Permissions\PermissionCatalogue;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

class RoleController
{
    public function index(): View
    {
        return view('identity-access::admin.roles.index', ['roles' => Role::query()->where('guard_name', 'admin')->withCount(['users', 'permissions'])->orderBy('name')->get()]);
    }

    public function create(): View
    {
        return view('identity-access::admin.roles.form', ['role' => null, 'grouped' => PermissionCatalogue::grouped(), 'granted' => []]);
    }

    public function store(RoleRequest $request, SaveRole $save): RedirectResponse
    {
        return $this->persist($request, $save, null);
    }

    public function edit(Role $role): View
    {
        abort_unless($role->guard_name === 'admin', 404);

        return view('identity-access::admin.roles.form', ['role' => $role, 'grouped' => PermissionCatalogue::grouped(), 'granted' => $role->permissions->pluck('name')->all()]);
    }

    public function update(RoleRequest $request, Role $role, SaveRole $save): RedirectResponse
    {
        abort_unless($role->guard_name === 'admin', 404);

        return $this->persist($request, $save, $role);
    }

    public function destroy(Request $request, Role $role, DeleteRole $delete): RedirectResponse
    {
        abort_unless($role->guard_name === 'admin', 404);

        try {
            /** @var User $actor */
            $actor = $request->user('admin');
            $delete->handle($actor, $role);
        } catch (DomainException $e) {
            return back()->withErrors(['role' => $e->getMessage()]);
        }

        return redirect()->route('admin.roles.index')->with('status', __('identity-access::admin.roles.deleted'));
    }

    private function persist(RoleRequest $request, SaveRole $save, ?Role $role): RedirectResponse
    {
        try {
            /** @var User $actor */
            $actor = $request->user('admin');
            $save->handle($actor, $role, (string) $request->validated('name'), $request->permissionNames());
        } catch (DomainException $e) {
            return back()->withInput()->withErrors(['permissions' => $e->getMessage()]);
        }

        return redirect()->route('admin.roles.index')->with('status', __('identity-access::admin.roles.saved'));
    }
}
