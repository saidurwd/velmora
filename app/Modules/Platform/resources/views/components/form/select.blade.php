@props(['name', 'label', 'options', 'value' => null, 'help' => null, 'placeholder' => null])
@php
    $id = 'f-'.str_replace(['[', ']', '.'], '-', $name);
    $error = $errors->first($name);
    $current = (string) old($name, $value);
@endphp
<div class="space-y-1.5">
    <label for="{{ $id }}" class="block text-sm font-medium">{{ $label }}</label>
    <select id="{{ $id }}" name="{{ $name }}" @if ($error) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
            {{ $attributes->class(['block w-full rounded-md border px-3 py-2 text-base admin:text-[0.9375rem]', 'border-line' => ! $error, 'border-danger' => $error]) }}>
        @if ($placeholder !== null)<option value="">{{ $placeholder }}</option>@endif
        @foreach ($options as $key => $text)<option value="{{ $key }}" @selected((string) $key === $current)>{{ $text }}</option>@endforeach
    </select>
    @if ($help)<p class="text-sm text-muted">{{ $help }}</p>@endif
    @if ($error)<p id="{{ $id }}-error" class="flex items-center gap-1.5 text-sm font-medium text-danger"><svg aria-hidden="true" class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 7.5v5.5M12 16.5h.01"/></svg>{{ $error }}</p>@endif
</div>
