@extends('layouts.admin')

@section('title', __('privacy::admin.title'))

@section('content')
<div class="max-w-5xl space-y-6">
    <h1 class="text-2xl font-bold">{{ __('privacy::admin.title') }}</h1>
    <p class="text-sm text-muted">{{ __('privacy::admin.help') }}</p>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    <form method="GET" action="{{ route('admin.privacy.index') }}" class="grid items-end gap-3 sm:grid-cols-3">
        <div><label for="type" class="block text-sm font-medium">{{ __('privacy::admin.type') }}</label>
            <select id="type" name="type" class="mt-1 block w-full rounded-md border border-line px-3 py-2"><option value="">{{ __('privacy::admin.any') }}</option>@foreach (['export', 'erasure'] as $t)<option value="{{ $t }}" @selected(($filters['type'] ?? '') === $t)>{{ __('privacy::admin.kinds.'.$t) }}</option>@endforeach</select></div>
        <div><label for="status" class="block text-sm font-medium">{{ __('privacy::admin.status') }}</label>
            <select id="status" name="status" class="mt-1 block w-full rounded-md border border-line px-3 py-2"><option value="">{{ __('privacy::admin.any') }}</option>@foreach ($statuses as $s)<option value="{{ $s }}" @selected(($filters['status'] ?? '') === $s)>{{ __('privacy::admin.states.'.$s) }}</option>@endforeach</select></div>
        <div><x-platform::btn type="submit" size="sm">{{ __('privacy::admin.filter') }}</x-platform::btn></div>
    </form>
    @if ($page->isEmpty())
        <p class="text-muted">{{ __('privacy::admin.none') }}</p>
    @else
    <div class="overflow-x-auto"><table class="w-full text-left text-sm">
        <caption class="sr-only">{{ __('privacy::admin.title') }}</caption>
        <thead><tr class="border-b border-line"><th scope="col" class="py-2 pr-3">{{ __('privacy::admin.asked') }}</th><th scope="col" class="py-2 pr-3">{{ __('privacy::admin.type') }}</th><th scope="col" class="py-2 pr-3">{{ __('privacy::admin.customer') }}</th><th scope="col" class="py-2 pr-3">{{ __('privacy::admin.status') }}</th></tr></thead>
        <tbody>
        @foreach ($page as $r)
            <tr class="border-b border-line align-top">
                <td class="py-2 pr-3"><a class="underline" href="{{ route('admin.privacy.show', ['publicId' => $r->public_id]) }}">{{ \App\Modules\Platform\Support\Fmt::dateTime(\Carbon\CarbonImmutable::parse($r->requested_at, 'UTC')) }}</a></td>
                <td class="py-2 pr-3">{{ __('privacy::admin.kinds.'.$r->type) }}</td>
                <td class="py-2 pr-3">{{ $r->customer?->name ?? __('privacy::admin.customer_gone') }}<div class="text-xs text-muted">{{ $r->customer?->publicId }}</div></td>
                <td class="py-2 pr-3"><x-platform::badge :tone="in_array($r->status, ['ready', 'done'], true) ? 'success' : (in_array($r->status, ['failed', 'rejected', 'blocked'], true) ? 'danger' : 'neutral')">{{ __('privacy::admin.states.'.$r->status) }}</x-platform::badge></td>
            </tr>
        @endforeach
        </tbody></table></div>
    {{ $page->links() }}
    @endif
</div>
@endsection
