<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

use App\Modules\Platform\DTOs\Money;
use Carbon\CarbonInterface;

/**
 * Locale-aware display formatting (dates in Asia/Dhaka, numbers, money). Storage stays ASCII/UTC; Bangla digits
 * are a display option controlled by the `display.bangla_digits` setting (BD-35).
 */
final class LocaleFormatter
{
    public function __construct(private readonly Settings $settings) {}

    private function locale(): string
    {
        return app()->getLocale();
    }

    private function digits(string $text): string
    {
        return ($this->locale() === 'bn' && $this->settings->bool('display.bangla_digits', false)) ? BanglaText::digitsToBangla($text) : $text;
    }

    public function date(CarbonInterface $at, string $style = 'medium'): string
    {
        $local = $at->copy()->setTimezone((string) config('app.display_timezone'))->locale($this->locale());

        return $this->digits($local->translatedFormat(match ($style) {
            'short' => 'd/m/Y',
            'long' => 'l, j F Y',
            default => 'j M Y',
        }));
    }

    public function dateTime(CarbonInterface $at): string
    {
        $local = $at->copy()->setTimezone((string) config('app.display_timezone'))->locale($this->locale());

        return $this->digits($local->translatedFormat('j M Y, h:i A'));
    }

    public function relative(CarbonInterface $at): string
    {
        return $this->digits($at->copy()->locale($this->locale())->diffForHumans());
    }

    public function number(int|float|string $value, int $decimals = 0): string
    {
        $fixed = number_format((float) $value, $decimals, '.', '');
        [$integer, $fraction] = array_pad(explode('.', ltrim($fixed, '-')), 2, '');
        $text = (str_starts_with($fixed, '-') ? '-' : '').Grouping::forLocale($integer, $this->locale()).($fraction !== '' ? '.'.$fraction : '');

        return $this->digits($text);
    }

    public function money(Money $money): string
    {
        return $money->format($this->locale(), $this->locale() === 'bn' && $this->settings->bool('display.bangla_digits', false));
    }
}
