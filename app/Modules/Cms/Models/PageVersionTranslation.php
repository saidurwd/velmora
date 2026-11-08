<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $version_id
 * @property string $locale
 * @property string $title
 * @property string $slug
 * @property string|null $body
 * @property string|null $seo_title
 * @property string|null $seo_description
 */
class PageVersionTranslation extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'cms_page_version_translations';

    protected $guarded = [];
}
