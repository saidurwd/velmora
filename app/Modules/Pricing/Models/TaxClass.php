<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property bool $is_default
 */
class TaxClass extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }
}
