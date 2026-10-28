@props(['translations' => null, 'fields', 'required' => null, 'group' => 'translations'])
{{-- Language tabs with one input per field. $translations: collection/array of translation rows (or null). $fields: field => ['label' => string, 'type' => text|textarea]. --}}
@php
    $locales = (array) config('app.supported_locales');
    $default = (string) config('app.default_locale');
    $labels = config('velmora.locale_labels');
    $rows = collect($translations ?? [])->keyBy('locale');
    $firstField = array_key_first($fields);
@endphp
<div x-data="{ tab: '{{ $default }}' }" class="space-y-4">
    <div role="tablist" aria-label="{{ __('platform::ui.languages') }}" class="flex gap-2 border-b border-line">
        @foreach ($locales as $locale)
            @php $missing = $locale !== $default && trim((string) old("{$group}.{$locale}.{$firstField}", $rows[$locale]->{$firstField} ?? '')) === ''; @endphp
            <button type="button" role="tab" id="tab-{{ $group }}-{{ $locale }}" aria-controls="pane-{{ $group }}-{{ $locale }}"
                    :aria-selected="tab === '{{ $locale }}'" @click="tab = '{{ $locale }}'"
                    class="-mb-px rounded-t-md border border-transparent px-4 py-2" :class="tab === '{{ $locale }}' ? 'border-line border-b-surface bg-surface font-semibold' : 'text-muted'">
                <span lang="{{ $locale }}">{{ $labels[$locale] ?? $locale }}</span>
                @if ($missing)<span class="ms-2 rounded bg-amber-100 px-1.5 py-0.5 text-xs text-warning">{{ __('platform::ui.missing') }}</span>@endif
            </button>
        @endforeach
    </div>

    @foreach ($locales as $locale)
        <div role="tabpanel" id="pane-{{ $group }}-{{ $locale }}" aria-labelledby="tab-{{ $group }}-{{ $locale }}" x-show="tab === '{{ $locale }}'" @if ($locale !== $default) x-cloak style="display:none" @endif class="space-y-4">
            @foreach ($fields as $field => $def)
                @php
                    $name = "{$group}[{$locale}][{$field}]";
                    $id = "f-{$group}-{$locale}-{$field}";
                    $value = old("{$group}.{$locale}.{$field}", $rows[$locale]->{$field} ?? '');
                    $isRequired = $required === $field && $locale === $default;
                    $error = $errors->first("{$group}.{$locale}.{$field}");
                @endphp
                <div class="space-y-1">
                    <label for="{{ $id }}" class="block text-sm font-medium">{{ $def['label'] }}@if ($isRequired)<span class="text-danger" aria-hidden="true"> *</span>@endif</label>
                    @if (($def['type'] ?? 'text') === 'textarea')
                        <textarea id="{{ $id }}" name="{{ $name }}" rows="{{ $def['rows'] ?? 6 }}" lang="{{ $locale }}" @if ($error) aria-invalid="true" @endif class="block w-full rounded-md border border-line px-3 py-2">{{ $value }}</textarea>
                    @else
                        <input id="{{ $id }}" name="{{ $name }}" value="{{ $value }}" lang="{{ $locale }}" @if ($isRequired) required aria-required="true" @endif @if ($error) aria-invalid="true" @endif class="block w-full rounded-md border border-line px-3 py-2">
                    @endif
                    @if ($error)<p class="text-sm text-danger">{{ $error }}</p>@endif
                </div>
            @endforeach
        </div>
    @endforeach
</div>
