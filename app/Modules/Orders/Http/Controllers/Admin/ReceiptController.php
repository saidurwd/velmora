<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers\Admin;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderReceipt;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Platform\Support\AuditLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** The printable receipt for staff. Printing it is recorded; a receipt whose figures do not add up is shown with a warning so it is not handed out. */
class ReceiptController
{
    public function show(Request $request, OrderReceipt $receipts, AuditLogger $audit, string $order): View
    {
        $model = Order::query()->where('public_id', $order)->firstOrFail();
        $user = $request->user('admin');
        abort_if($user === null, 403);
        $audit->record('order.receipt_printed', $model, [], [], new Actor(ActorType::Staff, (int) $user->getAuthIdentifier(), $user->public_id, $user->name));

        return view('orders::documents.receipt', ['receipt' => $receipts->for($model, $model->locale), 'staff' => true, 'back' => route('admin.orders.show', $model->public_id)]);
    }
}
