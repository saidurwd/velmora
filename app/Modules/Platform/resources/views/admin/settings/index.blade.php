@extends('layouts.admin')

@section('title', __('platform::settings.title'))

@section('content')
<div class="max-w-3xl space-y-6">
    <h1 class="text-2xl font-bold">{{ __('platform::settings.title') }}</h1>
    <ul class="grid gap-3 sm:grid-cols-2">
        @foreach ([['admin.settings.branding', 'platform::branding.menu', 'platform::settings.blurbs.branding', 'settings.view'], ['admin.settings.business', 'platform::settings.business.title', 'platform::settings.blurbs.business', 'settings.view'], ['admin.settings.tax', 'platform::settings.tax.title', 'platform::settings.blurbs.tax', 'settings.view'], ['admin.settings.locale', 'platform::settings.locale.title', 'platform::settings.blurbs.locale', 'settings.view'], ['admin.features.index', 'platform::settings.features.title', 'platform::settings.blurbs.features', 'settings.view'], ['admin.maintenance.show', 'platform::settings.maintenance.title', 'platform::settings.blurbs.maintenance', 'settings.view'], ['admin.integrations.index', 'platform::integrations.title', 'platform::settings.blurbs.integrations', 'integrations.manage'], ['admin.audit.index', 'platform::audit.title', 'platform::settings.blurbs.audit', 'audit.view']] as [$route, $label, $blurb, $permission])
            @can($permission)
                <li><a href="{{ route($route) }}" class="block rounded-md border border-line p-4 hover:bg-surface-alt"><span class="font-medium">{{ __($label) }}</span><span class="mt-1 block text-sm text-muted">{{ __($blurb) }}</span></a></li>
            @endcan
        @endforeach
    </ul>
</div>
@endsection
