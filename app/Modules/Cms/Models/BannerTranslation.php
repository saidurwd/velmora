<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $banner_id
 * @property string $locale
 * @property string|null $title
 * @property string|null $subtitle
 * @property string|null $cta_label
 * @property string|null $alt
 * @property array<string, string>|null $renditions width => path on the media disk
 * @property int|null $width
 * @property int|null $height
 */
class BannerTranslation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['renditions' => 'array'];
    }
}
