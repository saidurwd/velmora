@inject('saved', 'App\Modules\Engagement\Services\SavedState')
{{-- Under "Add to cart" on a product page: save for later, and "tell me when it is back" for an item that is out of stock. Services are booked, not saved. --}}
@if ($type !== 'service')
    @auth('web')
        @php $state = $saved->forVariant((int) auth('web')->id(), $variant); @endphp
        <div class="flex flex-wrap items-center gap-3 border-t border-line pt-3">
            @error('wishlist')<x-platform::alert type="error" class="w-full">{{ $message }}</x-platform::alert>@enderror
            @if ($state['saved'])
                <span class="text-sm">{{ __('engagement::wishlist.saved') }} · <a class="underline" href="{{ route('account.wishlist') }}">{{ __('engagement::wishlist.title') }}</a></span>
            @else
                <form method="POST" action="{{ route('account.wishlist.add') }}">@csrf
                    <input type="hidden" name="variant" value="{{ $variant }}">
                    <x-platform::btn type="submit" variant="secondary" size="sm">{{ __('engagement::wishlist.save') }}</x-platform::btn>
                </form>
            @endif
            @if ($outOfStock)
                @if ($state['alert'])
                    <span class="text-sm">{{ __('engagement::wishlist.alert_on') }}</span>
                @else
                    <form method="POST" action="{{ route('account.alerts.add') }}">@csrf
                        <input type="hidden" name="variant" value="{{ $variant }}">
                        <x-platform::btn type="submit" size="sm">{{ __('engagement::wishlist.alert_button') }}</x-platform::btn>
                    </form>
                @endif
            @endif
        </div>
    @else
        <p class="border-t border-line pt-3 text-sm"><a class="underline" href="{{ route('login') }}">{{ __($outOfStock ? 'engagement::wishlist.sign_in_to_alert' : 'engagement::wishlist.sign_in_to_save') }}</a></p>
    @endauth
@endif
