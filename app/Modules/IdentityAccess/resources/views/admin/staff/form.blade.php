@extends('layouts.admin')

@section('title', $staff ? __('identity-access::admin.staff.edit_title') : __('identity-access::admin.staff.add'))

@section('content')
<div class="max-w-xl space-y-6">
    <h1 class="text-2xl font-bold">{{ $staff ? __('identity-access::admin.staff.edit_title') : __('identity-access::admin.staff.add') }}</h1>
    <x-platform::form.errors />

    <form method="POST" action="{{ $staff ? route('admin.staff.update', $staff) : route('admin.staff.store') }}" class="space-y-4" novalidate>
        @csrf
        @if ($staff) @method('PUT') @endif

        <x-platform::form.field name="name" :value="$staff?->name" :label="__('identity-access::admin.staff.name')" autocomplete="name" required />
        @if ($staff)
            <p class="text-sm"><span class="font-medium">{{ __('identity-access::admin.staff.email') }}:</span> {{ $staff->email }}</p>
        @else
            <x-platform::form.field name="email" type="email" :label="__('identity-access::admin.staff.email')" :help="__('identity-access::admin.staff.invite_help')" autocomplete="off" required />
        @endif

        <fieldset class="space-y-2">
            <legend class="text-sm font-medium">{{ __('identity-access::admin.staff.roles') }}</legend>
            @php $current = old('roles', $staff?->getRoleNames()->all() ?? []); @endphp
            @foreach ($roles as $role)
                <label class="flex items-center gap-2">
                    <input type="checkbox" name="roles[]" value="{{ $role->name }}" @checked(in_array($role->name, $current, true)) class="size-4">
                    {{ $role->name }}
                </label>
            @endforeach
        </fieldset>

        <x-platform::form.button>{{ __('identity-access::admin.staff.save') }}</x-platform::form.button>
    </form>
</div>
@endsection
