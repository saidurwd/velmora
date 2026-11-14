@props([
    'name',
    'label',
    'type' => 'text',
    'value' => null,
    'autocomplete' => null,
    'inputmode' => null,
    'help' => null,
    'required' => false,
])
@php
    $id = 'f-'.str_replace(['[', ']', '.'], '-', $name);
    $error = $errors->first($name);
    $describedBy = trim(($help ? $id.'-help ' : '').($error ? $id.'-error' : ''));
@endphp
<div class="space-y-1.5">
    <label for="{{ $id }}" class="block text-sm font-medium">
        {{ $label }}@if ($required)<span class="text-danger" aria-hidden="true"> *</span>@endif
    </label>
    <input
        id="{{ $id }}"
        name="{{ $name }}"
        type="{{ $type }}"
        @if ($type !== 'password') value="{{ old($name, $value) }}" @endif
        @if ($autocomplete) autocomplete="{{ $autocomplete }}" @endif
        @if ($inputmode) inputmode="{{ $inputmode }}" @endif
        @if ($required) required aria-required="true" @endif
        @if ($error) aria-invalid="true" @endif
        @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
        {{ $attributes->class(['block w-full rounded-md border px-3 py-2 text-base admin:text-[0.9375rem]', 'border-line' => ! $error, 'border-danger' => $error]) }}
    >
    @if ($help)<p id="{{ $id }}-help" class="text-sm text-muted">{{ $help }}</p>@endif
    @if ($error)<p id="{{ $id }}-error" class="flex items-center gap-1.5 text-sm font-medium text-danger"><svg aria-hidden="true" class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 7.5v5.5M12 16.5h.01"/></svg>{{ $error }}</p>@endif
</div>
