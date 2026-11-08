{{-- The "I accept" box of sign-up, phone sign-in and checkout. $notices: list<PolicyNotice>. Sends accept_policies=1 and the release ids that were shown. --}}
@if (! empty($notices))
    <fieldset class="space-y-2 rounded-md border border-line p-3">
        <legend class="px-1 text-sm font-medium">{{ __('cms::policies.legend') }}</legend>
        @foreach ($notices as $n)<input type="hidden" name="policy_releases[]" value="{{ $n->releaseId }}">@endforeach
        <label class="flex items-start gap-2">
            <input type="checkbox" name="accept_policies" value="1" required class="mt-1" @checked(old('accept_policies'))>
            <span>{{ __('cms::policies.checkbox') }}
                @foreach ($notices as $n)
                    @if ($n->url !== null)<a class="underline" href="{{ $n->url }}" target="_blank" rel="noopener">{{ $n->title }}</a>@else{{ $n->title }}@endif{{ $loop->last ? '.' : ',' }}
                @endforeach
            </span>
        </label>
    </fieldset>
@endif
