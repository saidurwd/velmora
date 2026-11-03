<!doctype html>
<html lang="{{ $locale }}">
<body style="font-family: system-ui, sans-serif; line-height: 1.5; color: #111;">
<h1 style="font-size: 20px;">{{ $heading }}</h1>
<p>{{ $body }}</p>
@if ($link)<p><a href="{{ $link }}">{{ __('bookings::notify.link_text') }}</a></p>@endif
<p style="color: #555; font-size: 13px;">{{ __('bookings::notify.footer') }}</p>
</body>
</html>
