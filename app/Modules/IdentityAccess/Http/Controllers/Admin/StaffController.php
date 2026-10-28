<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Http\Controllers\Admin;

use App\Modules\IdentityAccess\Actions\CreateStaffUser;
use App\Modules\IdentityAccess\Actions\SetStaffStatus;
use App\Modules\IdentityAccess\Actions\UpdateStaffRoles;
use App\Modules\IdentityAccess\Enums\UserStatus;
use App\Modules\IdentityAccess\Http\Requests\StaffRequest;
use App\Modules\IdentityAccess\Models\User;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

class StaffController
{
    public function index(): View
    {
        return view('identity-access::admin.staff.index', [
            'staff' => User::query()->where('account_type', 'staff')->with('roles')->orderBy('name')->paginate(25),
        ]);
    }

    public function create(): View
    {
        return view('identity-access::admin.staff.form', ['staff' => null, 'roles' => Role::query()->where('guard_name', 'admin')->orderBy('name')->get()]);
    }

    public function store(StaffRequest $request, CreateStaffUser $create): RedirectResponse
    {
        try {
            $staff = $create->handle($this->actor($request), (string) $request->validated('name'), (string) $request->validated('email'), $request->roles(), (string) $request->validated('locale', 'en'));
        } catch (DomainException $e) {
            return back()->withInput()->withErrors(['roles' => $e->getMessage()]);
        }

        return redirect()->route('admin.staff.index')->with('status', __('identity-access::admin.staff.created', ['email' => $staff->email]));
    }

    public function edit(User $staff): View
    {
        abort_unless($staff->isStaff(), 404);

        return view('identity-access::admin.staff.form', ['staff' => $staff->load('roles'), 'roles' => Role::query()->where('guard_name', 'admin')->orderBy('name')->get()]);
    }

    public function update(StaffRequest $request, User $staff, UpdateStaffRoles $update): RedirectResponse
    {
        abort_unless($staff->isStaff(), 404);

        try {
            $update->handle($this->actor($request), $staff, (string) $request->validated('name'), $request->roles());
        } catch (DomainException $e) {
            return back()->withInput()->withErrors(['roles' => $e->getMessage()]);
        }

        return redirect()->route('admin.staff.index')->with('status', __('identity-access::admin.staff.updated'));
    }

    public function disable(Request $request, User $staff, SetStaffStatus $status): RedirectResponse
    {
        abort_unless($staff->isStaff(), 404);

        try {
            $status->handle($this->actor($request), $staff, UserStatus::Disabled);
        } catch (DomainException $e) {
            return back()->withErrors(['staff' => $e->getMessage()]);
        }

        return back()->with('status', __('identity-access::admin.staff.disabled'));
    }

    public function enable(Request $request, User $staff, SetStaffStatus $status): RedirectResponse
    {
        abort_unless($staff->isStaff(), 404);
        $status->handle($this->actor($request), $staff, UserStatus::Active);

        return back()->with('status', __('identity-access::admin.staff.enabled'));
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user('admin');

        return $user;
    }
}
