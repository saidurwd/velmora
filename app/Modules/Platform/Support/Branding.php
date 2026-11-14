<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Store identity editable from the admin panel: name, tagline per language, logo and the primary colour.
 * The colour drives the Tailwind `brand-*` tokens through CSS variables, so every component follows it.
 */
final class Branding
{
    public const DEFAULT_COLOR = '#0f6b5c';

    /** WCAG AA for normal text: white on the primary colour must reach this ratio. */
    public const MIN_CONTRAST = 4.5;

    /** The brand kit's logo files in public/brand (vector; the second is for dark backgrounds). */
    public const KIT_LOGO = 'logo-horizontal.svg';

    public const KIT_LOGO_ON_DARK = 'logo-horizontal-light.svg';

    public function __construct(private readonly Settings $settings) {}

    public function name(): string
    {
        return $this->settings->string('brand.name', (string) config('app.name'));
    }

    public function tagline(?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $value = $this->settings->string("brand.tagline.{$locale}");

        return $value !== '' ? $value : $this->settings->string('brand.tagline.'.config('app.default_locale'));
    }

    public function logoUrl(): ?string
    {
        $path = $this->settings->string('brand.logo');

        return $path === '' ? null : Storage::disk((string) config('catalog.media.disk', 'media'))->url($path);
    }

    /**
     * The logo to show: the brand kit's vector logo shipped in public/brand (sharp at any size, no background), the version for dark backgrounds when
     * $onDark; without a kit, the logo uploaded in the admin (light backgrounds only, as it may carry its own background); else null (show the name).
     */
    public function logo(bool $onDark = false): ?string
    {
        $file = 'brand/'.($onDark ? self::KIT_LOGO_ON_DARK : self::KIT_LOGO);
        if (is_file(public_path($file))) {
            return asset($file);
        }

        return $onDark ? null : $this->logoUrl();
    }

    public function color(): string
    {
        $color = $this->settings->string('brand.color', self::DEFAULT_COLOR);

        return self::isHex($color) ? strtolower($color) : self::DEFAULT_COLOR;
    }

    /** Inline CSS overriding the brand tokens. Values are validated hex, so the output cannot carry markup. */
    public function cssVariables(): string
    {
        $c = $this->color();

        return sprintf(
            ':root{--color-brand-50:%s;--color-brand-500:%s;--color-brand-600:%s;--color-brand-700:%s}',
            self::mix($c, '#ffffff', 0.92), self::mix($c, '#ffffff', 0.12), $c, self::mix($c, '#000000', 0.18),
        );
    }

    public static function isHex(string $color): bool
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1;
    }

    /** Contrast ratio of white text on this background colour (WCAG 2.x relative luminance). */
    public static function contrastWithWhite(string $hex): float
    {
        return 1.05 / (self::luminance($hex) + 0.05);
    }

    private static function luminance(string $hex): float
    {
        $channels = array_map(function (int $v): float {
            $s = $v / 255;

            return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        }, self::rgb($hex));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function rgb(string $hex): array
    {
        return [(int) hexdec(substr($hex, 1, 2)), (int) hexdec(substr($hex, 3, 2)), (int) hexdec(substr($hex, 5, 2))];
    }

    /** $amount of $with mixed into $base. */
    private static function mix(string $base, string $with, float $amount): string
    {
        $a = self::rgb($base);
        $b = self::rgb($with);

        return sprintf('#%02x%02x%02x', ...array_map(fn (int $i) => (int) round($a[$i] * (1 - $amount) + $b[$i] * $amount), [0, 1, 2]));
    }
}
