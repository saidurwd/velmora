<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

use App\Modules\Platform\Concerns\HasPublicId;
use App\Modules\Platform\Concerns\HasTranslations;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $public_id
 * @property string $status
 * @property CarbonImmutable|null $publish_at
 * @property CarbonImmutable|null $published_at
 */
class Page extends Model
{
    use HasPublicId, HasTranslations, SoftDeletes;

    protected $table = 'cms_pages';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['publish_at' => 'immutable_datetime', 'published_at' => 'immutable_datetime'];
    }

    protected function translationModel(): string
    {
        return PageTranslation::class;
    }

    protected function translationForeignKey(): string
    {
        return 'page_id';
    }

    public function isLive(?CarbonImmutable $now = null): bool
    {
        return $this->status === 'published' && ($this->publish_at === null || $this->publish_at->lessThanOrEqualTo($now ?? CarbonImmutable::now('UTC')));
    }
}
