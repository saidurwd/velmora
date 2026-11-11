@extends('layouts.admin')

@section('title', __('engagement::support.desk_title'))

@section('content')
<div class="max-w-6xl space-y-6">
    <h1 class="text-2xl font-bold">{{ __('engagement::support.desk_title') }}</h1>
    <p class="text-sm text-muted">{{ __('engagement::support.desk_help') }}</p>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    <x-platform::saved-filters screen="support" />
    <form method="GET" action="{{ route('admin.support.index') }}" class="grid items-end gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <div><label for="status" class="block text-sm font-medium">{{ __('engagement::support.status_label') }}</label>
            <select id="status" name="status" class="mt-1 block w-full rounded-md border border-line px-3 py-2">@foreach (['active', 'open', 'answered', 'closed'] as $s)<option value="{{ $s }}" @selected(($filters['status'] ?? 'active') === $s)>{{ __('engagement::support.filter.'.$s) }}</option>@endforeach</select></div>
        <div><label for="assignee" class="block text-sm font-medium">{{ __('engagement::support.assignee_label') }}</label>
            <select id="assignee" name="assignee" class="mt-1 block w-full rounded-md border border-line px-3 py-2"><option value="">{{ __('engagement::support.anyone') }}</option><option value="mine" @selected(($filters['assignee'] ?? '') === 'mine')>{{ __('engagement::support.mine') }}</option><option value="none" @selected(($filters['assignee'] ?? '') === 'none')>{{ __('engagement::support.unassigned') }}</option></select></div>
        <div><label for="category" class="block text-sm font-medium">{{ __('engagement::support.category_label') }}</label>
            <select id="category" name="category" class="mt-1 block w-full rounded-md border border-line px-3 py-2"><option value="">{{ __('engagement::support.any') }}</option>@foreach ($categories as $c)<option value="{{ $c }}" @selected(($filters['category'] ?? '') === $c)>{{ __('engagement::support.category.'.$c) }}</option>@endforeach</select></div>
        <div><label for="q" class="block text-sm font-medium">{{ __('engagement::support.search_reference') }}</label><input id="q" name="q" maxlength="12" value="{{ $filters['q'] ?? '' }}" class="mt-1 block w-full rounded-md border border-line px-3 py-2"></div>
        <label class="flex items-center gap-2 pb-2"><input type="checkbox" name="overdue" value="1" @checked(($filters['overdue'] ?? '') === '1') class="size-4"> {{ __('engagement::support.overdue_only') }}</label>
        <div><x-platform::btn type="submit" size="sm">{{ __('engagement::support.filter_button') }}</x-platform::btn></div>
    </form>
    @if ($page->isEmpty())
        <p class="text-muted">{{ __('engagement::support.none_tickets') }}</p>
    @else
        <div class="overflow-x-auto"><table class="w-full text-left text-sm">
            <caption class="sr-only">{{ __('engagement::support.desk_title') }}</caption>
            <thead><tr class="border-b border-line"><th scope="col" class="py-2 pr-3">{{ __('engagement::support.reference') }}</th><th scope="col" class="py-2 pr-3">{{ __('engagement::support.subject_label') }}</th><th scope="col" class="py-2 pr-3">{{ __('engagement::support.customer') }}</th><th scope="col" class="py-2 pr-3">{{ __('engagement::support.status_label') }}</th><th scope="col" class="py-2 pr-3">{{ __('engagement::support.assignee_label') }}</th><th scope="col" class="py-2 pr-3">{{ __('engagement::support.due') }}</th></tr></thead>
            <tbody>
            @foreach ($page as $t)
                <tr class="border-b border-line align-top">
                    <td class="py-2 pr-3"><a class="underline" href="{{ route('admin.support.show', $t->public_id) }}">{{ $t->reference }}</a></td>
                    <td class="py-2 pr-3">{{ $t->subject }}<div class="text-xs text-muted">{{ __('engagement::support.category.'.$t->category) }}@if ($t->order_number) · {{ $t->order_number }}@endif</div></td>
                    <td class="py-2 pr-3">{{ $t->customer ?? __('engagement::support.erased') }}</td>
                    <td class="py-2 pr-3"><x-platform::badge :tone="$t->overdue ? 'danger' : ($t->status === 'answered' ? 'success' : 'neutral')">{{ $t->overdue ? __('engagement::support.overdue') : __('engagement::support.status_staff.'.$t->status) }}</x-platform::badge></td>
                    <td class="py-2 pr-3">{{ $t->assignee ?? '—' }}</td>
                    <td class="py-2 pr-3">{{ $t->first_responded_at === null ? \App\Modules\Platform\Support\Fmt::dateTime(\Carbon\CarbonImmutable::parse($t->sla_due_at, 'UTC')) : '—' }}</td>
                </tr>
            @endforeach
            </tbody></table></div>
        {{ $page->links() }}
    @endif
</div>
@endsection
