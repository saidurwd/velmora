<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $provider
 * @property string $provider_event_id
 * @property int|null $payment_id
 * @property array<string, scalar|null> $payload
 * @property bool|null $verified
 * @property string $status
 * @property string|null $error
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable|null $processed_at
 */
class PaymentWebhookEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array', 'verified' => 'boolean', 'received_at' => 'immutable_datetime', 'processed_at' => 'immutable_datetime'];
    }
}
