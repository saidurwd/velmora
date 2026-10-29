<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $order_id
 * @property int $variant_id
 * @property string $product_type
 * @property array<string, string> $name_snapshot
 * @property string $sku_snapshot
 * @property array<string, string>|null $options_snapshot locale => readable options
 * @property int $quantity
 * @property int $unit_price_minor
 * @property int $discount_minor
 * @property int $tax_rate_bp
 * @property int $tax_minor
 * @property int $line_total_minor
 * @property int $fulfilled_qty
 * @property int $returned_qty
 */
class OrderItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['name_snapshot' => 'array', 'options_snapshot' => 'array'];
    }

    /** "Size: M" in the reader's language, or null. */
    public function options(string $locale): ?string
    {
        $options = $this->options_snapshot;
        if ($options === null || $options === []) {
            return null;
        }

        return (string) ($options[$locale] ?? $options[(string) config('app.default_locale')] ?? reset($options));
    }

    /** The name in the reader's language, falling back to English then to anything we have. */
    public function name(string $locale): string
    {
        $names = $this->name_snapshot;

        return (string) ($names[$locale] ?? $names[(string) config('app.default_locale')] ?? (reset($names) ?: ''));
    }
}
