@props(['name', 'label', 'options', 'value' => null, 'help' => null, 'placeholder' => null])
@php
    $id = 'f-'.str_replace(['[', ']', '.'], '-', $name);
    $error = $errors->first($name);
    $current = (string) old($name, $value);
@endphp
<div class="space-y-1">
    <label for="{{ $id }}" class="block text-sm font-medium">{{ $label }}</label>
    <select id="{{ $id }}" name="{{ $name }}" @if ($error) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
            {{ $attributes->class(['block w-full rounded-md border px-3 py-2 text-base', 'border-line' => ! $error, 'border-danger' => $error]) }}>
        @if ($placeholder !== null)<option value="">{{ $placeholder }}</option>@endif
        @foreach ($options as $key => $text)<option value="{{ $key }}" @selected((string) $key === $current)>{{ $text }}</option>@endforeach
    </select>
    @if ($help)<p class="text-sm text-muted">{{ $help }}</p>@endif
    @if ($error)<p id="{{ $id }}-error" class="text-sm text-danger">{{ $error }}</p>@endif
</div>
