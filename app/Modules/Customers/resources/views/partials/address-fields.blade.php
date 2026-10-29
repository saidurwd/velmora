{{-- Shared by the address book and checkout. $geo = divisions / districts / upazilas; $address = AddressView|null. Works without JavaScript:
     every district and upazila is listed under its parent heading, and the server checks the chain. With JavaScript only the matching ones show. --}}
@php
    $val = fn (string $key, $fallback = '') => old($key, $fallback);
    $division = (int) $val('division_id', $address?->divisionId ?? 0);
    $district = (int) $val('district_id', $address?->districtId ?? 0);
    $upazila = (int) $val('upazila_id', $address?->upazilaId ?? 0);
    $districtName = collect($geo['districts'])->flatten(1)->pluck('name', 'id');
@endphp
<div x-data="geoSelect({{ $division }}, {{ $district }})" class="space-y-4">
    <x-platform::form.field name="recipient" :value="$address?->recipient" :label="__('customers::account.addresses.recipient')" autocomplete="name" required />
    <x-platform::form.field name="phone" type="tel" inputmode="tel" :value="$address?->phone ? '0'.substr($address->phone, 4) : ''" :label="__('customers::account.addresses.phone')" :help="__('customers::account.addresses.phone_help')" autocomplete="tel" required />

    <div class="space-y-1">
        <label for="division_id" class="block text-sm font-medium">{{ __('customers::account.addresses.division') }} <span class="text-danger" aria-hidden="true">*</span></label>
        <select id="division_id" name="division_id" required x-model.number="division" @change="district = 0" class="block w-full rounded-md border border-line px-3 py-2 text-base">
            <option value="">{{ __('customers::account.addresses.choose') }}</option>
            @foreach ($geo['divisions'] as $d)<option value="{{ $d['id'] }}" @selected($division === $d['id'])>{{ $d['name'] }}</option>@endforeach
        </select>
        @error('division_id')<p class="text-sm text-danger">{{ $message }}</p>@enderror
    </div>

    <div class="space-y-1">
        <label for="district_id" class="block text-sm font-medium">{{ __('customers::account.addresses.district') }} <span class="text-danger" aria-hidden="true">*</span></label>
        <select id="district_id" name="district_id" required x-model.number="district" x-ref="district" class="block w-full rounded-md border border-line px-3 py-2 text-base">
            <option value="">{{ __('customers::account.addresses.choose') }}</option>
            @foreach ($geo['divisions'] as $d)
                <optgroup label="{{ $d['name'] }}" data-parent="{{ $d['id'] }}" :hidden="division && division !== {{ $d['id'] }}" :disabled="division && division !== {{ $d['id'] }}">
                    @foreach ($geo['districts'][$d['id']] ?? [] as $dist)<option value="{{ $dist['id'] }}" @selected($district === $dist['id'])>{{ $dist['name'] }}</option>@endforeach
                </optgroup>
            @endforeach
        </select>
        @error('district_id')<p class="text-sm text-danger">{{ $message }}</p>@enderror
    </div>

    <div class="space-y-1">
        <label for="upazila_id" class="block text-sm font-medium">{{ __('customers::account.addresses.upazila') }} <span class="text-danger" aria-hidden="true">*</span></label>
        <select id="upazila_id" name="upazila_id" required class="block w-full rounded-md border border-line px-3 py-2 text-base" aria-describedby="upazila-help">
            <option value="">{{ __('customers::account.addresses.choose') }}</option>
            @foreach ($geo['upazilas'] as $districtId => $list)
                <optgroup label="{{ $districtName[$districtId] ?? '' }}" :hidden="district && district !== {{ $districtId }}" :disabled="district && district !== {{ $districtId }}">
                    @foreach ($list as $u)<option value="{{ $u['id'] }}" @selected($upazila === $u['id'])>{{ $u['name'] }}</option>@endforeach
                </optgroup>
            @endforeach
        </select>
        <p id="upazila-help" class="text-sm text-muted">{{ __('customers::account.addresses.upazila_help') }}</p>
        @error('upazila_id')<p class="text-sm text-danger">{{ $message }}</p>@enderror
    </div>

    <x-platform::form.field name="area" :value="$address?->area" :label="__('customers::account.addresses.area')" :help="__('customers::account.addresses.area_help')" autocomplete="address-level3" />
    <x-platform::form.field name="address_line" :value="$address?->addressLine" :label="__('customers::account.addresses.line')" :help="__('customers::account.addresses.line_help')" autocomplete="street-address" required />
    <x-platform::form.field name="landmark" :value="$address?->landmark" :label="__('customers::account.addresses.landmark')" />
    <x-platform::form.field name="delivery_note" :value="$address?->deliveryNote" :label="__('customers::account.addresses.note')" />
    <x-platform::form.field name="postal_code" inputmode="numeric" :value="$address?->postalCode" :label="__('customers::account.addresses.postal')" autocomplete="postal-code" />
</div>
