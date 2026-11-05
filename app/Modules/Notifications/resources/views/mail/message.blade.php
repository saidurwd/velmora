<!doctype html>
<html lang="{{ $locale }}">
<body style="font-family: system-ui, sans-serif; line-height: 1.5; color: #111;">
<p style="font-size: 13px; color: #555;">{{ config('app.name') }}</p>
<div>{!! nl2br(e($text)) !!}</div>
</body>
</html>
