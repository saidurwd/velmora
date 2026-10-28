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
<div class="space-y-1">
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
        {{ $attributes->class(['block w-full rounded-md border px-3 py-2 text-base', 'border-line' => ! $error, 'border-danger' => $error]) }}
    >
    @if ($help)<p id="{{ $id }}-help" class="text-sm text-muted">{{ $help }}</p>@endif
    @if ($error)<p id="{{ $id }}-error" class="text-sm text-danger">{{ $error }}</p>@endif
</div>
