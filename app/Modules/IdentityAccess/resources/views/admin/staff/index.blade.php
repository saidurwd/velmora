@extends('layouts.admin')

@section('title', __('identity-access::admin.staff.title'))

@section('content')
<div class="space-y-6">
    <div class="flex items-center justify-between gap-4">
        <h1 class="text-2xl font-bold">{{ __('identity-access::admin.staff.title') }}</h1>
        @can('staff.create')
            <a href="{{ route('admin.staff.create') }}" class="inline-flex min-h-11 items-center rounded-md bg-brand-600 px-5 py-2 font-medium text-white hover:bg-brand-700">{{ __('identity-access::admin.staff.add') }}</a>
        @endcan
    </div>

    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    <x-platform::form.errors />

    <table class="w-full text-sm">
        <caption class="sr-only">{{ __('identity-access::admin.staff.title') }}</caption>
        <thead><tr class="border-b border-line">
            <th scope="col" class="py-2 text-start">{{ __('identity-access::admin.staff.name') }}</th>
            <th scope="col" class="py-2 text-start">{{ __('identity-access::admin.staff.email') }}</th>
            <th scope="col" class="py-2 text-start">{{ __('identity-access::admin.staff.roles') }}</th>
            <th scope="col" class="py-2 text-start">{{ __('identity-access::admin.staff.status') }}</th>
            <th scope="col" class="py-2"><span class="sr-only">{{ __('identity-access::admin.staff.actions') }}</span></th>
        </tr></thead>
        <tbody>
        @foreach ($staff as $member)
            <tr class="border-b border-line align-top">
                <td class="py-2">{{ $member->name }}</td>
                <td class="py-2">{{ $member->email }}</td>
                <td class="py-2">{{ $member->roles->pluck('name')->join(', ') }}</td>
                <td class="py-2">{{ __('identity-access::admin.status.'.$member->status->value) }}</td>
                <td class="flex gap-2 py-2">
                    @can('staff.update')<a class="underline" href="{{ route('admin.staff.edit', $member) }}">{{ __('identity-access::admin.staff.edit') }}</a>@endcan
                    @can('staff.disable')
                        <form method="POST" action="{{ route($member->status->value === 'active' ? 'admin.staff.disable' : 'admin.staff.enable', $member) }}">
                            @csrf
                            <button class="underline">{{ $member->status->value === 'active' ? __('identity-access::admin.staff.disable') : __('identity-access::admin.staff.enable') }}</button>
                        </form>
                    @endcan
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
    {{ $staff->links() }}
</div>
@endsection
