@php
    /** @var \Illuminate\Database\Eloquent\Model|null $entity  @var list<string> $fields */
    $locales = (array) config('app.supported_locales');
    $default = (string) config('app.default_locale');
    $labels = config('velmora.locale_labels');
    $existing = $entity?->translations?->keyBy('locale') ?? collect();
@endphp
<div x-data="{ tab: '{{ $default }}' }" class="space-y-4">
    <div role="tablist" aria-label="{{ __('catalog::admin.languages') }}" class="flex gap-2 border-b border-line">
        @foreach ($locales as $locale)
            @php $missing = $locale !== $default && trim((string) old("translations.{$locale}.name", $existing[$locale]->name ?? '')) === ''; @endphp
            <button type="button" role="tab" id="tab-{{ $locale }}" aria-controls="pane-{{ $locale }}"
                    :aria-selected="tab === '{{ $locale }}'" @click="tab = '{{ $locale }}'"
                    class="-mb-px rounded-t-md border border-transparent px-4 py-2" :class="tab === '{{ $locale }}' ? 'border-line border-b-surface bg-surface font-semibold' : 'text-muted'">
                <span lang="{{ $locale }}">{{ $labels[$locale] ?? $locale }}</span>
                @if ($missing)<span class="ms-2 rounded bg-amber-100 px-1.5 py-0.5 text-xs text-warning">{{ __('catalog::admin.missing') }}</span>@endif
            </button>
        @endforeach
    </div>

    @foreach ($locales as $locale)
        <div role="tabpanel" id="pane-{{ $locale }}" aria-labelledby="tab-{{ $locale }}" x-show="tab === '{{ $locale }}'" @if ($locale !== $default) x-cloak style="display:none" @endif class="space-y-4">
            @foreach ($fields as $field)
                @php
                    $name = "translations[{$locale}][{$field}]";
                    $value = old("translations.{$locale}.{$field}", $existing[$locale]->{$field} ?? '');
                    $required = $locale === $default && $field === 'name';
                    $long = in_array($field, ['description'], true);
                @endphp
                <div class="space-y-1">
                    <label for="{{ 'f-'.$locale.'-'.$field }}" class="block text-sm font-medium">{{ __('catalog::admin.fields.'.$field) }}@if ($required)<span class="text-danger" aria-hidden="true"> *</span>@endif</label>
                    @if ($long)
                        <textarea id="{{ 'f-'.$locale.'-'.$field }}" name="{{ $name }}" rows="6" lang="{{ $locale }}" class="block w-full rounded-md border border-line px-3 py-2">{{ $value }}</textarea>
                    @else
                        <input id="{{ 'f-'.$locale.'-'.$field }}" name="{{ $name }}" value="{{ $value }}" lang="{{ $locale }}" @if ($required) required aria-required="true" @endif class="block w-full rounded-md border border-line px-3 py-2">
                    @endif
                </div>
            @endforeach
        </div>
    @endforeach
</div>
