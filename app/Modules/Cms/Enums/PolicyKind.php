<?php

declare(strict_types=1);

namespace App\Modules\Cms\Enums;

/** The legal pages customers can be asked to accept. One page per kind. */
enum PolicyKind: string
{
    case Terms = 'terms';
    case Privacy = 'privacy';
    case Returns = 'returns';
    case Shipping = 'shipping';
    case Cod = 'cod';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $k) => $k->value, self::cases());
    }

    /**
     * Which kinds a moment in the customer's journey asks them to accept. Returns and shipping are shown as links only.
     *
     * @return list<string>
     */
    public static function acceptedAt(string $context, ?string $paymentMethod = null): array
    {
        $kinds = [self::Terms->value, self::Privacy->value];

        return $context === 'checkout' && $paymentMethod === 'cod' ? [...$kinds, self::Cod->value] : $kinds;
    }
}
