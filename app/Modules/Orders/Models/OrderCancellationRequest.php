<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Modules\Platform\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property int $order_id
 * @property int $user_id
 * @property string $status
 * @property string|null $note
 * @property string|null $decision_note
 */
class OrderCancellationRequest extends Model
{
    use HasPublicId;

    protected $guarded = ['*'];   // written only by the Actions, never from a request
}
