@props(['title', 'text' => null])
<div {{ $attributes->class('motif rounded-2xl border border-dashed border-line bg-surface-alt p-10 text-center') }}>
    <p class="text-lg font-semibold">{{ $title }}</p>
    @if ($text)<p class="mt-1 text-muted">{{ $text }}</p>@endif
    <div class="mt-4">{{ $slot }}</div>
</div>
