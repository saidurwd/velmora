<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Platform\Contracts\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Raises a reconciliation exception (something finance must look at), once per problem. */
final class PaymentExceptions
{
    public function __construct(private readonly Clock $clock) {}

    /** @param  array<string, mixed>  $details */
    public function raise(string $type, ?int $paymentId, ?int $orderId, string $reference, array $details): void
    {
        $now = $this->clock->now();
        DB::table('reconciliation_exceptions')->insertOrIgnore([
            'public_id' => strtolower((string) Str::ulid()), 'type' => $type, 'payment_id' => $paymentId, 'order_id' => $orderId, 'reference' => mb_substr($reference, 0, 120),
            'details' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'status' => 'open', 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
}
