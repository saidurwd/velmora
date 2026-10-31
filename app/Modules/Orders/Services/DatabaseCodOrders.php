<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Contracts\CodOrders;
use App\Modules\Orders\DTOs\CodOrderInfo;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\PaymentStatus;
use App\Modules\Orders\Models\Order;

final class DatabaseCodOrders implements CodOrders
{
    public function find(string $orderPublicId): ?CodOrderInfo
    {
        $order = Order::query()->where('public_id', $orderPublicId)->first();

        return $order === null ? null : $this->info($order);
    }

    public function awaitingCash(): array
    {
        // serves the aging report: COD orders past confirmation with nothing paid, oldest first
        return array_values(Order::query()
            ->where('payment_method_code', CodPaymentInitiator::CODE)
            ->whereIn('status', [OrderStatus::Confirmed->value, OrderStatus::Processing->value, OrderStatus::Completed->value])
            ->where('payment_status', PaymentStatus::Unpaid->value)
            ->orderBy('created_at')->orderBy('id')
            ->get()->map(fn (Order $o): CodOrderInfo => $this->info($o))->all());
    }

    private function info(Order $order): CodOrderInfo
    {
        return new CodOrderInfo($order->id, $order->public_id, $order->order_number, $order->grand_total_minor, $order->currency, $order->payment_method_code === CodPaymentInitiator::CODE, $order->status, $order->payment_status, $order->created_at->toImmutable(), $order->fulfillment_status->hasShipped());
    }
}
