<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $page_id
 * @property string $locale
 * @property string $title
 * @property string $slug
 * @property string|null $body
 * @property string|null $seo_title
 * @property string|null $seo_description
 */
class PageTranslation extends Model
{
    protected $table = 'cms_page_translations';

    protected $guarded = [];
}
