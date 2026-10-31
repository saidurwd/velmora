<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Contracts\OrderPayables;
use App\Modules\Orders\DTOs\OrderPaymentRequest;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\PaymentStatus;
use App\Modules\Orders\Models\Order;

final class DatabaseOrderPayables implements OrderPayables
{
    public function __construct(private readonly OrderPaymentRequests $requests) {}

    public function payable(string $orderPublicId): ?OrderPaymentRequest
    {
        $order = Order::query()->where('public_id', $orderPublicId)->first();

        return $order !== null && $this->open($order) ? $this->requests->for($order) : null;
    }

    public function isPayable(int $orderId): bool
    {
        $order = Order::query()->find($orderId);

        return $order !== null && $this->open($order);
    }

    private function open(Order $order): bool
    {
        return in_array($order->status, [OrderStatus::Pending, OrderStatus::Confirmed, OrderStatus::Processing], true)
            && in_array($order->payment_status, [PaymentStatus::Unpaid, PaymentStatus::Pending, PaymentStatus::Failed], true)
            && $order->payment_method_code !== CodPaymentInitiator::CODE;
    }
}
