<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Exceptions\CheckoutException;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * One checkout at a time per phone number. "At most three open cash orders per phone" is a count, and two simultaneous orders
 * could each see two and both go ahead; a count cannot be made safe by a row lock alone because the new order does not exist
 * yet. The lock is held from before the order transaction starts until after it ends, and only blocks the same phone.
 */
final class PhoneLock
{
    private const WAIT_SECONDS = 10;

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     *
     * @throws CheckoutException busy
     */
    public function run(string $phoneE164, Closure $work): mixed
    {
        $name = 'vm:phone:'.substr(AddressFingerprint::phone($phoneE164), 0, 40);

        if ((int) (DB::selectOne('SELECT GET_LOCK(?, ?) AS got', [$name, self::WAIT_SECONDS])->got ?? 0) !== 1) {
            throw new CheckoutException('busy');
        }

        try {
            return $work();
        } finally {
            DB::select('SELECT RELEASE_LOCK(?)', [$name]);
        }
    }
}
