<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers\Api;

use App\Modules\Orders\Actions\CancelOwnOrder;
use App\Modules\Orders\Actions\ReorderOrder;
use App\Modules\Orders\Actions\RequestOrderCancellation;
use App\Modules\Orders\Contracts\ApiOrderAccess;
use App\Modules\Orders\Contracts\OrderPayables;
use App\Modules\Orders\DTOs\OrderAccess;
use App\Modules\Orders\Exceptions\OrderException;
use App\Modules\Orders\Http\Resources\OrderDetail;
use App\Modules\Orders\Http\Resources\OrderSummary;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Contracts\PaymentMethodCatalog;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Platform\Enums\ErrorCode;
use App\Modules\Platform\Http\ApiProblem;
use Dedoc\Scramble\Attributes\Response as ResponseDoc;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

/**
 * The customer's orders. Every route finds the order through ApiOrderAccess: its signed-in owner, or a guest holding the order's own token (who may look, pay and
 * track, nothing else). Somebody else's order and a missing one are the same 404.
 */
class OrderController
{
    private const MAX_LIMIT = 50;

    public function __construct(private readonly ApiOrderAccess $access, private readonly PaymentMethodCatalog $methods, private readonly OrderPayables $payables) {}

    /**
     * My orders
     *
     * Newest first, `limit` (1 to 50, default 20) at a time. `meta.next_cursor` is `null` on the last page; otherwise send it back as `cursor`. Only the signed-in customer's own orders.
     */
    #[ResponseDoc(status: 200, type: 'array{data: list<'.OrderSummary::TYPE.'>, meta: array{limit: int, next_cursor: string|null}}')]
    public function index(Request $request): JsonResponse
    {
        $request->validate(['limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LIMIT], 'cursor' => ['nullable', 'string', 'max:60']]);
        $limit = (int) $request->query('limit', 20);
        $after = $this->decodeCursor($request->query('cursor'));

        $rows = Order::query()->where('user_id', (int) $request->user()?->getAuthIdentifier())
            ->when($after !== null, fn ($q) => $q->where('id', '<', $after))->orderByDesc('id')->limit($limit + 1)->get();   // index (user_id, id)
        $more = $rows->count() > $limit;
        $page = $rows->take($limit);

        return response()->json([
            'data' => $page->map(fn (Order $o): array => OrderSummary::make($o))->values()->all(),
            'meta' => ['limit' => $limit, 'next_cursor' => $more ? $this->encodeCursor($page->last()?->id) : null],
        ]);
    }

    /**
     * One order
     *
     * Items at the price they were bought at, the delivery address, how it was paid, and `available_actions` (`pay`, `track`, `invoice`, and for the owner `cancel`, `request_cancel`, `reorder`, `return`): show a button for exactly these. A guest sends `X-Order-Token` instead of signing in; the phone number is then masked.
     *
     * @unauthenticated
     */
    #[ResponseDoc(status: 200, type: 'array{data: '.OrderDetail::TYPE.'}')]
    public function show(Request $request, string $id): JsonResponse
    {
        $access = $this->grant($request, $id);
        $order = Order::query()->whereKey($access->orderId)->firstOrFail();

        return response()->json(['data' => OrderDetail::make($order, $access->isOwner, app()->getLocale(), $this->methods, $this->payables->isPayable($order->id))]);
    }

    /**
     * Cancel an order
     *
     * If the order may still be cancelled by its owner (not yet confirmed, not paid, nothing sent) it is cancelled now (`result: cancelled`). If it is already being handled but nothing has shipped, a cancellation request goes to the shop (`result: requested`, 202). Otherwise `order.not_cancellable` with the reason in `detail`.
     */
    #[ResponseDoc(status: 200, type: 'array{data: array{result: string}}')]
    #[ResponseDoc(status: 202, type: 'array{data: array{result: string}}')]
    public function cancel(Request $request, string $id, CancelOwnOrder $cancel, RequestOrderCancellation $ask): JsonResponse
    {
        $access = $this->owner($request, $id);
        $request->validate(['note' => ['nullable', 'string', 'max:150']]);
        $order = Order::query()->whereKey($access->orderId)->with('items')->firstOrFail();
        $note = $request->input('note');
        $note = is_string($note) ? $note : null;

        try {
            if (CancelOwnOrder::deniedReason($order) === null) {
                $cancel->handle(new Actor(ActorType::Customer, (int) $access->ownerUserId, (string) ($request->user()->public_id ?? '')), $access->publicId, $note);

                return response()->json(['data' => ['result' => 'cancelled']]);
            }

            $ask->handle((int) $access->ownerUserId, $access->publicId, $note);

            return response()->json(['data' => ['result' => 'requested']], 202);
        } catch (OrderException $e) {
            throw new ApiProblem(ErrorCode::OrderNotCancellable, ['params' => ['reason' => $e->reason]], detail: __('orders::account.errors.'.$e->reason, $e->params));
        }
    }

    /**
     * Buy it again
     *
     * Puts the order's items in the cart at today's price, under the cart's own rules. Each line says whether it went in, and why not if it did not. Nothing is bought and no stock is held; fetch `GET /cart` next.
     */
    #[ResponseDoc(status: 200, type: 'array{data: list<array{name: string, requested: int, added: int, status: string, reason: string|null, params: array<string, mixed>}>}')]
    public function reorder(Request $request, string $id, ReorderOrder $reorder): JsonResponse
    {
        $access = $this->owner($request, $id);

        try {
            $lines = $reorder->handle((int) $access->ownerUserId, $access->publicId, app()->getLocale());
        } catch (ModelNotFoundException) {
            throw new ApiProblem(ErrorCode::NotFound);
        }

        return response()->json(['data' => array_map(fn (array $l): array => ['params' => (object) $l['params']] + $l, $lines)]);
    }

    /**
     * Invoice
     *
     * A short-lived signed link to the printable receipt (open it in a browser or share it). It stops working after `expires_at`; ask again for a new one.
     *
     * @unauthenticated
     */
    #[ResponseDoc(status: 200, type: 'array{data: array{url: string, expires_at: string}}')]
    public function invoice(Request $request, string $id): JsonResponse
    {
        $access = $this->grant($request, $id);
        $expires = now()->addMinutes(30);

        return response()->json(['data' => [
            'url' => URL::temporarySignedRoute('orders.receipt', $expires, ['locale' => app()->getLocale(), 'order' => $access->publicId]),
            'expires_at' => $expires->toIso8601String(),
        ]]);
    }

    private function grant(Request $request, string $id): OrderAccess
    {
        return $this->access->find($request, $id) ?? throw new ApiProblem(ErrorCode::NotFound);
    }

    private function owner(Request $request, string $id): OrderAccess
    {
        $access = $this->grant($request, $id);
        $access->isOwner || throw new ApiProblem(ErrorCode::NotFound); // a guest's token never cancels or re-orders

        return $access;
    }

    private function encodeCursor(?int $id): ?string
    {
        return $id === null ? null : rtrim(strtr(base64_encode('o:'.$id), '+/', '-_'), '=');
    }

    private function decodeCursor(mixed $cursor): ?int
    {
        if (! is_string($cursor) || $cursor === '') {
            return null;
        }
        $raw = (string) base64_decode(strtr($cursor, '-_', '+/'), true);

        return preg_match('/^o:(\d{1,18})$/', $raw, $m) === 1 ? (int) $m[1] : throw new ValidationException(validator(['cursor' => $cursor], ['cursor' => ['prohibited']]));
    }
}
