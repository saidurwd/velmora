<?php

declare(strict_types=1);

namespace App\Modules\DigitalProducts\Models;

use App\Modules\Platform\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property int $variant_id
 * @property int|null $created_by
 */
class LicenseKeyPool extends Model
{
    use HasPublicId;

    protected $guarded = [];
}
