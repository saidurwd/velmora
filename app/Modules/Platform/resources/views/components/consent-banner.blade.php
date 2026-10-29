{{-- Analytics consent. Shown only with JavaScript and only when no choice has been made (and never when the browser sends Do-Not-Track / Global Privacy Control). --}}
<div x-data="consentBanner()" id="privacy-controls">
    <section x-show="open" x-cloak style="display:none" x-ref="banner" tabindex="-1" role="region" aria-labelledby="consent-h"
             class="fixed inset-x-0 bottom-0 z-40 border-t border-line bg-surface p-4 shadow-lg">
        <div class="mx-auto flex max-w-6xl flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="max-w-3xl">
                <h2 id="consent-h" class="font-semibold">{{ __('platform::ui.consent.title') }}</h2>
                <p class="text-sm text-muted">{{ __('platform::ui.consent.text') }}</p>
            </div>
            <div class="flex shrink-0 gap-2">
                <button type="button" @click="accept()" class="inline-flex min-h-11 items-center rounded-md bg-brand-600 px-5 py-2 font-medium text-white hover:bg-brand-700">{{ __('platform::ui.consent.accept') }}</button>
                <button type="button" @click="decline()" class="inline-flex min-h-11 items-center rounded-md border border-line px-5 py-2 hover:bg-surface-alt">{{ __('platform::ui.consent.decline') }}</button>
            </div>
        </div>
    </section>
    <button type="button" @click="reopen()" x-show="true" x-cloak style="display:none" class="text-sm text-muted underline hover:text-ink">{{ __('platform::ui.consent.settings') }}</button>
</div>
