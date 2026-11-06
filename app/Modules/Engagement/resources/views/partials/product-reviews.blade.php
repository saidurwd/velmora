@inject('reviews', 'App\Modules\Engagement\Services\ReviewReader')
{{-- On a product page: the star summary and the published reviews, each shown in the language it was written in. --}}
@php
    $data = $reviews->forVariant($variant, app()->getLocale());
    $stats = $data['stats'] ?? null;
@endphp
@if ($data !== null && $stats['count'] > 0)
<section aria-labelledby="reviews-h" class="max-w-3xl space-y-4">
    <h2 id="reviews-h" class="text-xl font-semibold">{{ __('engagement::reviews.heading') }}</h2>
    <div class="flex flex-wrap items-start gap-8">
        <div>
            <p class="text-3xl font-bold">{{ \App\Modules\Platform\Support\Fmt::number(round($stats['average'], 1), 1) }}</p>
            <x-platform::rating :value="$stats['average']" :count="$stats['count']" />
        </div>
        <dl class="min-w-48 flex-1 space-y-1 text-sm">
            @foreach ([5, 4, 3, 2, 1] as $star)
                <div class="flex items-center gap-2">
                    <dt class="w-16">{{ __('engagement::reviews.stars', ['count' => \App\Modules\Platform\Support\Fmt::number($star)]) }}</dt>
                    <dd class="flex flex-1 items-center gap-2"><span class="block h-2 flex-1 rounded bg-surface-alt" role="presentation"><span class="block h-2 rounded bg-warning" style="width: {{ round(100 * $stats['stars'][$star] / $stats['count']) }}%"></span></span><span>{{ \App\Modules\Platform\Support\Fmt::number($stats['stars'][$star]) }}</span></dd>
                </div>
            @endforeach
        </dl>
    </div>
    <ul class="space-y-4">
        @foreach ($data['page'] as $r)
            <li class="space-y-1 border-t border-line pt-4" @if ($r->locale !== app()->getLocale()) lang="{{ $r->locale }}" @endif>
                <p><span aria-hidden="true" class="text-warning">{{ str_repeat('★', (int) $r->rating) }}{{ str_repeat('☆', 5 - (int) $r->rating) }}</span><span class="sr-only">{{ __('engagement::reviews.stars', ['count' => \App\Modules\Platform\Support\Fmt::number($r->rating)]) }}</span>
                    @if ($r->title)<span class="ms-2 font-medium">{{ $r->title }}</span>@endif</p>
                <p class="whitespace-pre-line">{{ $r->body }}</p>
                <p class="text-sm text-muted">{{ $r->author_name ?? __('engagement::reviews.anonymous') }} · {{ \App\Modules\Platform\Support\Fmt::date(\Carbon\CarbonImmutable::parse($r->created_at, 'UTC')) }} · {{ __('engagement::reviews.verified') }}@if ($r->locale !== app()->getLocale()) · {{ __('engagement::reviews.written_in.'.$r->locale) }}@endif</p>
            </li>
        @endforeach
    </ul>
    {{ $data['page']->links() }}
</section>
@endif
