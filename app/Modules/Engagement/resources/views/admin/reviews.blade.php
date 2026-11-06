@extends('layouts.admin')

@section('title', __('engagement::reviews.queue_title'))

@section('content')
<div class="max-w-5xl space-y-6">
    <h1 class="text-2xl font-bold">{{ __('engagement::reviews.queue_title') }}</h1>
    <p class="text-sm text-muted">{{ __('engagement::reviews.queue_help') }}</p>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    @error('review')<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror
    <form method="GET" action="{{ route('admin.reviews.index') }}" class="flex items-end gap-3">
        <div><label for="status" class="block text-sm font-medium">{{ __('engagement::reviews.state') }}</label>
            <select id="status" name="status" class="mt-1 block rounded-md border border-line px-3 py-2">@foreach (['pending', 'published', 'rejected', 'withdrawn'] as $s)<option value="{{ $s }}" @selected(($filters['status'] ?? 'pending') === $s)>{{ __('engagement::reviews.status.'.$s) }}</option>@endforeach</select></div>
        <x-platform::btn type="submit" size="sm">{{ __('engagement::reviews.filter') }}</x-platform::btn>
    </form>
    @if ($page->isEmpty())
        <p class="text-muted">{{ __('engagement::reviews.queue_none') }}</p>
    @else
        <ul class="space-y-4">
            @foreach ($page as $r)
                <li class="space-y-2 rounded-lg border border-line p-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="font-medium">{{ $r->product_name }}</span>
                        <span class="text-sm text-muted">{{ $r->author_name ?? __('engagement::reviews.anonymous') }} · {{ strtoupper($r->locale) }} · {{ \App\Modules\Platform\Support\Fmt::dateTime(\Carbon\CarbonImmutable::parse($r->created_at, 'UTC')) }}</span>
                    </div>
                    <p><span aria-hidden="true" class="text-warning">{{ str_repeat('★', (int) $r->rating) }}{{ str_repeat('☆', 5 - (int) $r->rating) }}</span><span class="sr-only">{{ __('engagement::reviews.stars', ['count' => \App\Modules\Platform\Support\Fmt::number($r->rating)]) }}</span></p>
                    @if ($r->title)<p class="font-medium">{{ $r->title }}</p>@endif
                    <p class="whitespace-pre-line">{{ $r->body }}</p>
                    @if ($r->rejection_reason)<p class="text-sm text-muted">{{ __('engagement::reviews.reason', ['reason' => $r->rejection_reason]) }}</p>@endif
                    @if (in_array($r->status, ['pending', 'published'], true))
                        <div class="flex flex-wrap items-end gap-3">
                            @if ($r->status === 'pending')
                                <form method="POST" action="{{ route('admin.reviews.approve', $r->public_id) }}">@csrf
                                    <x-platform::btn type="submit" size="sm">{{ __('engagement::reviews.approve') }}<span class="sr-only"> — {{ $r->product_name }}</span></x-platform::btn>
                                </form>
                            @endif
                            <form method="POST" action="{{ route('admin.reviews.reject', $r->public_id) }}" class="flex flex-wrap items-end gap-2">@csrf
                                <div><label for="why-{{ $r->public_id }}" class="block text-xs font-medium">{{ __('engagement::reviews.reason_label') }}</label>
                                    <input id="why-{{ $r->public_id }}" name="reason" maxlength="255" required class="mt-1 rounded-md border border-line px-2 py-1"></div>
                                <x-platform::btn type="submit" size="sm" variant="secondary">{{ $r->status === 'published' ? __('engagement::reviews.take_down') : __('engagement::reviews.reject') }}<span class="sr-only"> — {{ $r->product_name }}</span></x-platform::btn>
                            </form>
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
        {{ $page->links() }}
    @endif
</div>
@endsection
