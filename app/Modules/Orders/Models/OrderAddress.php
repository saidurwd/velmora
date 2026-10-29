<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $order_id
 * @property string $type
 * @property string $recipient_name
 * @property string $phone
 * @property array<string, array<string, string>> $location_snapshot
 * @property string|null $postal_code
 * @property string|null $area
 * @property string $address_line
 * @property string|null $landmark
 * @property string|null $delivery_note
 * @property string|null $fingerprint
 */
class OrderAddress extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['location_snapshot' => 'array'];
    }

    /** "Upazila, District, Division" in the reader's language. */
    public function locationLine(string $locale): string
    {
        $default = (string) config('app.default_locale');
        $parts = [];
        foreach (['upazila', 'district', 'division'] as $level) {
            $names = $this->location_snapshot[$level] ?? [];
            $name = $names[$locale] ?? $names[$default] ?? null;
            if ($name !== null && $name !== '') {
                $parts[] = $name;
            }
        }

        return implode(', ', $parts);
    }
}
