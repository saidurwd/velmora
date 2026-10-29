<?php

declare(strict_types=1);

namespace App\Modules\Customers\Models;

use App\Modules\Platform\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property int $customer_id
 * @property string|null $label
 * @property string $recipient
 * @property string $phone
 * @property int $division_id
 * @property int $district_id
 * @property int $upazila_id
 * @property string|null $area
 * @property string $address_line
 * @property string|null $landmark
 * @property string|null $delivery_note
 * @property string|null $postal_code
 * @property bool $is_default
 */
class CustomerAddress extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }
}
