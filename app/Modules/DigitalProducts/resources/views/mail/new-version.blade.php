<!doctype html>
<html lang="{{ $locale }}">
<body style="font-family: system-ui, sans-serif; line-height: 1.5; color: #111;">
<h1 style="font-size: 20px;">{{ __('digital-products::mail.new_version_heading', ['product' => $product]) }}</h1>
<p>{{ __('digital-products::mail.new_version_text', ['version' => $version]) }}</p>
<p><a href="{{ $link }}">{{ __('digital-products::mail.new_version_link') }}</a></p>
<p style="color: #555; font-size: 13px;">{{ __('digital-products::mail.footer') }}</p>
</body>
</html>
