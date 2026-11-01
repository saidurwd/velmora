<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers\Web;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderReceipt;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;

/** A customer's printable receipt. Reached only through a link WE signed (their order page, the confirmation page); it expires. Never shown if its figures do not add up. */
class ReceiptController
{
    public function show(OrderReceipt $receipts, string $order): View
    {
        $model = Order::query()->where('public_id', $order)->firstOrFail();
        $receipt = $receipts->for($model, app()->getLocale());
        if (! $receipt['reconciles']) {
            Log::error('orders.receipt_does_not_reconcile', ['order' => $model->public_id]);
            abort(404);
        }

        return view('orders::documents.receipt', ['receipt' => $receipt, 'staff' => false, 'back' => null]);
    }
}
