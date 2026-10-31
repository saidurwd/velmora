<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Models;

use App\Modules\Platform\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property string $courier
 * @property string $label
 * @property bool $is_active
 * @property array<string, mixed>|null $credentials
 * @property array<string, mixed>|null $settings
 * @property int $sort_order
 */
class CourierAccount extends Model
{
    use HasPublicId;

    protected $guarded = [];

    /** Credentials never leave the server: not in JSON, not in arrays, not in logs. */
    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'credentials' => 'encrypted:array', 'settings' => 'array'];
    }
}
