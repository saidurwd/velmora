<?php

declare(strict_types=1);

namespace App\Modules\DigitalProducts\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $pool_id
 * @property string $key_encrypted
 * @property string $fingerprint
 * @property string $status
 * @property int|null $entitlement_id
 * @property CarbonImmutable|null $allocated_at
 * @property CarbonImmutable $created_at
 */
class LicenseKey extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    /** The key is only ever decrypted here, where a person is about to be shown it. */
    protected $hidden = ['key_encrypted'];

    protected function casts(): array
    {
        return ['allocated_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }
}
