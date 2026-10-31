<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Notifications\Contracts\SmsGateway;
use App\Modules\Orders\Models\Order;
use App\Modules\Platform\DTOs\PhoneNumber;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Throwable;

/** A text message to the customer of an order, in the language they ordered in. A message that cannot be sent never undoes what it announces. */
final class CustomerSms
{
    public function __construct(private readonly SmsGateway $sms) {}

    /** @param  array<string, scalar>  $params */
    public function send(Order $order, string $key, array $params, string $purpose): void
    {
        $previous = App::getLocale();
        App::setLocale($order->locale);
        try {
            $this->sms->send(PhoneNumber::parse($order->contact_phone), __('orders::notifications.'.$key, ['shop' => (string) config('app.name'), 'number' => $order->order_number] + $params), ['purpose' => $purpose]);
        } catch (Throwable $e) {
            Log::warning('orders.sms_failed', ['order' => $order->order_number, 'purpose' => $purpose, 'error' => $e::class]);
        } finally {
            App::setLocale($previous);
        }
    }
}
