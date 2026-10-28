<?php

declare(strict_types=1);

namespace App\Modules\Platform\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * External identifier (BD-21): a ULID in `public_id`. Internal auto-increment ids never leave the system.
 *
 * @mixin Model
 */
trait HasPublicId
{
    public static function bootHasPublicId(): void
    {
        static::creating(function (Model $model): void {
            if (empty($model->getAttribute('public_id'))) {
                $model->setAttribute('public_id', strtolower((string) Str::ulid()));
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
