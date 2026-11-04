@extends('layouts.admin')

@section('title', __('bookings::admin.title'))

@php $fmt = fn ($d) => \App\Modules\Platform\Support\Fmt::dateTime($d); @endphp

@section('content')
<div class="max-w-6xl space-y-6">
    <header class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-bold">{{ __('bookings::admin.title') }}</h1>
        <a href="{{ route('admin.bookings.calendar') }}" class="inline-flex min-h-11 items-center underline">{{ __('bookings::admin.menu.calendar') }}</a>
    </header>
    <form method="GET" action="{{ route('admin.bookings.index') }}" class="grid items-end gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <div><label for="status" class="block text-sm font-medium">{{ __('bookings::admin.col.status') }}</label>
            <select id="status" name="status" class="mt-1 block w-full rounded-md border border-line px-3 py-2"><option value="">{{ __('bookings::admin.any') }}</option>
                @foreach ($statuses as $s)<option value="{{ $s }}" @selected(($filters['status'] ?? '') === $s)>{{ __('bookings::mine.status.'.$s) }}</option>@endforeach</select></div>
        <div><label for="from" class="block text-sm font-medium">{{ __('bookings::admin.from') }}</label><input id="from" type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="mt-1 block w-full rounded-md border border-line px-3 py-2"></div>
        <div><label for="to" class="block text-sm font-medium">{{ __('bookings::admin.to') }}</label><input id="to" type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="mt-1 block w-full rounded-md border border-line px-3 py-2"></div>
        <div><label for="resource" class="block text-sm font-medium">{{ __('bookings::admin.col.resource') }}</label>
            <select id="resource" name="resource" class="mt-1 block w-full rounded-md border border-line px-3 py-2"><option value="">{{ __('bookings::admin.any') }}</option>
                @foreach ($resources as $r)<option value="{{ $r->public_id }}" @selected(($filters['resource'] ?? '') === $r->public_id)>{{ $r->name }}</option>@endforeach</select></div>
        <div><label for="sku" class="block text-sm font-medium">{{ __('bookings::admin.sku') }}</label><input id="sku" name="sku" value="{{ $filters['sku'] ?? '' }}" maxlength="64" class="mt-1 block w-full rounded-md border border-line px-3 py-2"></div>
        <div><label for="order" class="block text-sm font-medium">{{ __('bookings::admin.col.order') }}</label><input id="order" name="order" value="{{ $filters['order'] ?? '' }}" maxlength="30" class="mt-1 block w-full rounded-md border border-line px-3 py-2"></div>
        <div class="sm:col-span-3 lg:col-span-6"><x-platform::btn type="submit" size="sm">{{ __('bookings::admin.filter') }}</x-platform::btn></div>
    </form>

    @if ($page->isEmpty())
        <p class="text-muted">{{ __('bookings::admin.none') }}</p>
    @else
    <div class="overflow-x-auto">
    <table class="w-full text-left text-sm">
        <caption class="sr-only">{{ __('bookings::admin.title') }}</caption>
        <thead><tr class="border-b border-line">
            <th scope="col" class="py-2 pr-3">{{ __('bookings::admin.col.when') }}</th><th scope="col" class="py-2 pr-3">{{ __('bookings::admin.col.service') }}</th><th scope="col" class="py-2 pr-3">{{ __('bookings::admin.col.resource') }}</th>
            <th scope="col" class="py-2 pr-3">{{ __('bookings::admin.col.order') }}</th><th scope="col" class="py-2 pr-3">{{ __('bookings::admin.col.phone') }}</th><th scope="col" class="py-2 pr-3">{{ __('bookings::admin.col.paid') }}</th><th scope="col" class="py-2 pr-3">{{ __('bookings::admin.col.status') }}</th>
        </tr></thead>
        <tbody>
        @foreach ($page as $r)
            <tr class="border-b border-line align-top">
                <td class="py-2 pr-3"><a href="{{ route('admin.bookings.show', ['booking' => $r['public_id']]) }}" class="inline-flex min-h-11 items-center underline">{{ $fmt($r['starts_at']) }}</a></td>
                <td class="py-2 pr-3">{{ $r['service'] }} <span class="text-muted">({{ $r['sku'] }})</span></td><td class="py-2 pr-3">{{ $r['resource'] }}</td>
                <td class="py-2 pr-3">{{ $r['order_number'] }}</td><td class="py-2 pr-3">{{ $r['phone'] }}</td><td class="py-2 pr-3">{{ \App\Modules\Platform\Support\Fmt::money($r['paid']) }}</td>
                <td class="py-2 pr-3"><x-platform::badge :tone="in_array($r['status'], ['confirmed', 'in_progress', 'completed'], true) ? 'success' : ($r['status'] === 'held' ? 'neutral' : 'danger')">{{ __('bookings::mine.status.'.$r['status']) }}</x-platform::badge></td>
            </tr>
        @endforeach
        </tbody>
    </table>
    </div>
    {{ $page->links() }}
    @endif
</div>
@endsection
