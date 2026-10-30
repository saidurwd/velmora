<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Modules\Platform\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $code
 * @property string $provider
 * @property string $type
 * @property bool $is_enabled
 * @property int|null $min_minor
 * @property int|null $max_minor
 * @property array<string, mixed>|null $restrictions
 * @property array<string, mixed>|null $fee_rule
 * @property int $sort_order
 */
class PaymentMethod extends Model
{
    use HasTranslations;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'restrictions' => 'array', 'fee_rule' => 'array'];
    }

    protected function translationModel(): string
    {
        return PaymentMethodTranslation::class;
    }

    protected function translationForeignKey(): string
    {
        return 'method_id';
    }
}
