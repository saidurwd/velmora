<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $locale
 * @property string $name
 * @property string $slug
 * @property string|null $short_description
 * @property string|null $description
 */
class ProductTranslation extends Model
{
    protected $guarded = [];
}
