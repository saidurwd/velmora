@if ($errors->any())
    <div role="alert" tabindex="-1" id="error-summary" class="flex items-start gap-3 rounded-md border border-danger/40 bg-red-50 p-4 text-red-950" x-data x-init="$el.focus()">
        <svg aria-hidden="true" class="mt-0.5 size-5 shrink-0 text-danger" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3.5 21.5 20h-19Z"/><path d="M12 10v4M12 17h.01"/></svg>
        <div class="min-w-0">
            <p class="font-semibold text-danger">{{ __('platform::forms.error_summary') }}</p>
            <ul class="mt-2 list-disc space-y-0.5 ps-5 text-sm">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    </div>
@endif
