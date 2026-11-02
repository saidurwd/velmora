<?php

declare(strict_types=1);

namespace App\Modules\DigitalProducts\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $asset_id
 * @property int $version
 * @property string $storage_key
 * @property string $original_filename
 * @property string $mime
 * @property int $size_bytes
 * @property string $sha256
 * @property int|null $uploaded_by
 * @property CarbonImmutable $created_at
 */
class DigitalAssetVersion extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }
}
