<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One saved state of a page. Immutable: the table rejects UPDATE and DELETE.
 *
 * @property int $id
 * @property int $page_id
 * @property int $number
 * @property int|null $created_by
 * @property CarbonImmutable $created_at
 */
class PageVersion extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'cms_page_versions';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Page, $this> */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'page_id');
    }

    /** @return HasMany<PageVersionTranslation, $this> */
    public function translations(): HasMany
    {
        return $this->hasMany(PageVersionTranslation::class, 'version_id');
    }

    /** The title staff recognise the version by: default language first, then whatever exists. */
    public function title(): string
    {
        if ($this->translations->isEmpty()) {
            return '';
        }

        $default = (string) config('app.default_locale');

        return (string) ($this->translations->firstWhere('locale', $default) ?? $this->translations->firstOrFail())->title;
    }
}
