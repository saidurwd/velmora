<?php

declare(strict_types=1);

namespace App\Modules\Customers\Models;

use App\Modules\Platform\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * Customer profile (also used for guests: user_id null). The identity (login) lives in IdentityAccess;
 * this module never touches the users table or model.
 *
 * @property int $id
 * @property string $public_id
 * @property int|null $user_id
 * @property string|null $name
 * @property string|null $phone
 * @property string|null $email
 * @property bool $marketing_consent
 */
class Customer extends Model
{
    use HasPublicId;

    protected $fillable = ['name', 'birth_date'];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'marketing_consent' => 'boolean',
            'marketing_consent_at' => 'datetime',
            'anonymised_at' => 'datetime',
        ];
    }
}
