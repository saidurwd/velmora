@props(['title', 'text' => null])
<div {{ $attributes->class('rounded-lg border border-dashed border-line p-8 text-center') }}>
    <p class="text-lg font-medium">{{ $title }}</p>
    @if ($text)<p class="mt-1 text-muted">{{ $text }}</p>@endif
    <div class="mt-4">{{ $slot }}</div>
</div>
