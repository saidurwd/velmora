<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $locale
 * @property string $label
 */
class AttributeValueTranslation extends Model
{
    public $timestamps = false;

    protected $guarded = [];
}
