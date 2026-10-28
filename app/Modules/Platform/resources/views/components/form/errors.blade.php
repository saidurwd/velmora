@if ($errors->any())
    <div role="alert" tabindex="-1" id="error-summary" class="rounded-md border border-danger bg-red-50 p-4" x-data x-init="$el.focus()">
        <p class="font-semibold text-danger">{{ __('platform::forms.error_summary') }}</p>
        <ul class="mt-2 list-disc ps-5 text-sm">
            @foreach ($errors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif
