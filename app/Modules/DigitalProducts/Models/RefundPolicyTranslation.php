<?php

declare(strict_types=1);

namespace App\Modules\DigitalProducts\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $policy_id
 * @property string $locale
 * @property string $title
 * @property string $text
 */
class RefundPolicyTranslation extends Model
{
    public $timestamps = false;

    protected $guarded = [];
}
