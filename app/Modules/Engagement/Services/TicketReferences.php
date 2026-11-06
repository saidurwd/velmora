<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Services;

use Illuminate\Support\Facades\DB;

/** A short code the customer can quote ("K7M2-Q9XD"): random, from letters and digits that are hard to confuse, never a sequence number. */
final class TicketReferences
{
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public function make(): string
    {
        for ($i = 0; $i < 10; $i++) {
            $code = '';
            foreach (range(1, 8) as $_) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $reference = substr($code, 0, 4).'-'.substr($code, 4);
            if (! DB::table('support_tickets')->where('reference', $reference)->exists()) {   // unique reference
                return $reference;
            }
        }

        throw new \RuntimeException('Could not make a ticket reference.');
    }
}
