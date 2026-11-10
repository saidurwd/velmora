{{-- The shop's legal identity for receipts and packing slips. Prints only what staff entered; prints nothing when nothing was entered. --}}
@php($b = app(\App\Modules\Platform\Support\BusinessProfile::class))
@unless ($b->isEmpty())
    <section aria-label="{{ __('platform::settings.business.title') }}" class="space-y-1 border-t border-line pt-3 text-sm">
        @if ($b->legalName() !== '')<p class="font-semibold">{{ $b->legalName() }}</p>@endif
        @if ($b->address() !== '')<p class="whitespace-pre-line">{{ $b->address() }}</p>@endif
        @if ($b->phone() !== '' || $b->email() !== '')<p>{{ collect([$b->phone(), $b->email()])->filter()->join(' · ') }}</p>@endif
        @if ($b->bin() !== '')<p>{{ __('platform::settings.business.bin_label') }}: {{ $b->bin() }}</p>@endif
        @if ($b->tin() !== '')<p>{{ __('platform::settings.business.tin_label') }}: {{ $b->tin() }}</p>@endif
        @if ($b->invoiceFooter() !== '')<p class="whitespace-pre-line text-muted">{{ $b->invoiceFooter() }}</p>@endif
    </section>
@endunless
