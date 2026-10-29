<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Models;

use App\Modules\Platform\Concerns\HasPublicId;
use App\Modules\Platform\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property string $code
 * @property bool $cod_available
 * @property int $cod_fee_minor
 * @property int|null $free_over_minor
 * @property bool $is_active
 */
class ShippingMethod extends Model
{
    use HasPublicId, HasTranslations;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['cod_available' => 'boolean', 'is_active' => 'boolean'];
    }

    protected function translationModel(): string
    {
        return ShippingMethodTranslation::class;
    }

    protected function translationForeignKey(): string
    {
        return 'method_id';
    }
}
