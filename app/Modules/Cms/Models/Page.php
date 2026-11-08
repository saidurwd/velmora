<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

use App\Modules\Platform\Concerns\HasPublicId;
use App\Modules\Platform\Concerns\HasTranslations;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $public_id
 * @property string $status
 * @property CarbonImmutable|null $publish_at
 * @property CarbonImmutable|null $published_at
 * @property int|null $draft_version_id
 * @property int|null $live_version_id
 * @property int|null $scheduled_version_id
 * @property string|null $kind
 * @property bool $schedule_requires_acceptance
 */
class Page extends Model
{
    use HasPublicId, HasTranslations, SoftDeletes;

    protected $table = 'cms_pages';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['publish_at' => 'immutable_datetime', 'published_at' => 'immutable_datetime', 'schedule_requires_acceptance' => 'boolean'];
    }

    protected function translationModel(): string
    {
        return PageTranslation::class;
    }

    protected function translationForeignKey(): string
    {
        return 'page_id';
    }

    /** @return HasMany<PageVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(PageVersion::class, 'page_id')->orderByDesc('number');
    }

    /** @return BelongsTo<PageVersion, $this> */
    public function draftVersion(): BelongsTo
    {
        return $this->belongsTo(PageVersion::class, 'draft_version_id');
    }

    /** @return BelongsTo<PageVersion, $this> */
    public function liveVersion(): BelongsTo
    {
        return $this->belongsTo(PageVersion::class, 'live_version_id');
    }

    /** @return BelongsTo<PageVersion, $this> */
    public function scheduledVersion(): BelongsTo
    {
        return $this->belongsTo(PageVersion::class, 'scheduled_version_id');
    }

    /** What the public sees. A scheduled version is not live until the job publishes it. */
    public function isLive(): bool
    {
        return $this->status === 'published';
    }
}
