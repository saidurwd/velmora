@extends('layouts.admin')

@section('title', $role ? $role->name : __('identity-access::admin.roles.add'))

@section('content')
@php
    $locked = $role?->name === \App\Modules\IdentityAccess\Permissions\PermissionCatalogue::SUPER_ADMIN;
    $system = $role && \App\Modules\IdentityAccess\Permissions\PermissionCatalogue::isSystemRole($role->name);
    $checked = old('permissions', $granted);
    $canManage = auth('admin')->user()?->can('roles.manage');
@endphp
<div class="max-w-3xl space-y-6">
    <h1 class="text-2xl font-bold">{{ $role ? $role->name : __('identity-access::admin.roles.add') }}</h1>
    @if ($locked)<x-platform::alert type="info">{{ __('identity-access::admin.roles.super_admin_locked') }}</x-platform::alert>@endif
    <x-platform::form.errors />

    <form method="POST" action="{{ $role ? route('admin.roles.update', ['role' => $role->name]) : route('admin.roles.store') }}" class="space-y-6" novalidate>
        @csrf
        @if ($role) @method('PUT') @endif

        @if ($system)
            <input type="hidden" name="name" value="{{ $role->name }}">
        @else
            <x-platform::form.field name="name" :value="$role?->name" :label="__('identity-access::admin.roles.name')" required />
        @endif

        @foreach ($grouped as $resource => $actions)
            <fieldset class="rounded-md border border-line p-4">
                <legend class="px-2 font-medium">{{ __('identity-access::permissions.resources.'.$resource) }}</legend>
                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach ($actions as $action)
                        @php $name = $resource.'.'.$action; @endphp
                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="permissions[]" value="{{ $name }}" @checked(in_array($name, $checked, true)) @disabled($locked || ! $canManage) class="size-4">
                            <span>{{ __('identity-access::permissions.actions.'.str_replace('.', '_', $action)) }} <span class="text-xs text-muted">({{ $name }})</span></span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
        @endforeach

        @if ($canManage && ! $locked)<x-platform::form.button>{{ __('identity-access::admin.staff.save') }}</x-platform::form.button>@endif
    </form>

    @if ($role && ! $system && $canManage)
        <form method="POST" action="{{ route('admin.roles.destroy', ['role' => $role->name]) }}">
            @csrf @method('DELETE')
            <x-platform::form.button variant="secondary">{{ __('identity-access::admin.roles.delete') }}</x-platform::form.button>
        </form>
    @endif
</div>
@endsection
