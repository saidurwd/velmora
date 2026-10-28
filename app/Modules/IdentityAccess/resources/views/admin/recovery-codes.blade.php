@extends('layouts.admin-guest')

@section('title', __('identity-access::admin.recovery.title'))

@section('content')
<div class="space-y-6">
    <h1 class="text-2xl font-bold">{{ __('identity-access::admin.recovery.title') }}</h1>
    <x-platform::alert type="info">{{ __('identity-access::admin.recovery.warning') }}</x-platform::alert>
    <ul class="grid grid-cols-2 gap-2 rounded-md border border-line p-4 font-mono">
        @foreach ($codes as $code)<li>{{ $code }}</li>@endforeach
    </ul>
    <a href="{{ route('admin.dashboard') }}" class="inline-flex min-h-11 items-center rounded-md bg-brand-600 px-5 py-2 font-medium text-white hover:bg-brand-700">{{ __('identity-access::admin.recovery.continue') }}</a>
</div>
@endsection
