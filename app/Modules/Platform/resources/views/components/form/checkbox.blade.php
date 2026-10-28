@props(['name', 'label', 'value' => '1', 'checked' => false])
<label class="flex min-h-11 items-center gap-2">
    <input type="checkbox" name="{{ $name }}" value="{{ $value }}" @checked(old($name, $checked)) {{ $attributes->class('size-5 rounded border-line') }}>
    <span>{{ $label }}</span>
</label>
