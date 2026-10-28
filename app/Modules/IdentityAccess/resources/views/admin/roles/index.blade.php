@extends('layouts.admin')

@section('title', __('identity-access::admin.roles.title'))

@section('content')
<div class="space-y-6">
    <div class="flex items-center justify-between gap-4">
        <h1 class="text-2xl font-bold">{{ __('identity-access::admin.roles.title') }}</h1>
        @can('roles.manage')
            <a href="{{ route('admin.roles.create') }}" class="inline-flex min-h-11 items-center rounded-md bg-brand-600 px-5 py-2 font-medium text-white hover:bg-brand-700">{{ __('identity-access::admin.roles.add') }}</a>
        @endcan
    </div>

    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    <x-platform::form.errors />

    <table class="w-full text-sm">
        <caption class="sr-only">{{ __('identity-access::admin.roles.title') }}</caption>
        <thead><tr class="border-b border-line">
            <th scope="col" class="py-2 text-start">{{ __('identity-access::admin.roles.name') }}</th>
            <th scope="col" class="py-2 text-start">{{ __('identity-access::admin.roles.permissions') }}</th>
            <th scope="col" class="py-2 text-start">{{ __('identity-access::admin.roles.members') }}</th>
            <th scope="col" class="py-2"><span class="sr-only">{{ __('identity-access::admin.staff.actions') }}</span></th>
        </tr></thead>
        <tbody>
        @foreach ($roles as $role)
            <tr class="border-b border-line">
                <td class="py-2">{{ $role->name }}</td>
                <td class="py-2">{{ $role->permissions_count }}</td>
                <td class="py-2">{{ $role->users_count }}</td>
                <td class="py-2"><a class="underline" href="{{ route('admin.roles.edit', ['role' => $role->name]) }}">{{ __('identity-access::admin.roles.view') }}</a></td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endsection
