@props(['value' => 0, 'count' => 0])
{{-- Hidden until reviews exist (Phase 9). --}}
@if ($count > 0)
    <span {{ $attributes->class('inline-flex items-center gap-1 text-sm') }}>
        <span class="text-warning" aria-hidden="true">{{ str_repeat('★', (int) round($value)) }}{{ str_repeat('☆', 5 - (int) round($value)) }}</span>
        <span class="sr-only">{{ __('platform::ui.rating', ['value' => \App\Modules\Platform\Support\Fmt::number(round($value, 1), 1), 'count' => \App\Modules\Platform\Support\Fmt::number($count)]) }}</span>
        <span class="text-muted" aria-hidden="true">({{ \App\Modules\Platform\Support\Fmt::number($count) }})</span>
    </span>
@endif
