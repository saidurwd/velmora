<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Platform\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $public_id
 * @property int $product_id
 * @property string $sku
 * @property bool $is_default
 * @property bool $track_inventory
 * @property string $option_signature
 * @property string $status
 * @property string|null $barcode
 * @property int|null $weight_g
 * @property int|null $length_mm
 * @property int|null $width_mm
 * @property int|null $height_mm
 * @property int $min_qty
 * @property int|null $max_qty
 * @property int $qty_step
 */
class ProductVariant extends Model
{
    use HasPublicId, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'track_inventory' => 'boolean'];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsToMany<AttributeValue, $this> */
    public function optionValues(): BelongsToMany
    {
        return $this->belongsToMany(AttributeValue::class, 'variant_option_values', 'variant_id', 'attribute_value_id');
    }
}
