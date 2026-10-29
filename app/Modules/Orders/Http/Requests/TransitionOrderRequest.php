<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Authorised by the route's permission middleware (orders.update / orders.cancel is checked again in the controller for cancellations). */
class TransitionOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'axis' => ['required', 'in:status,payment,fulfillment'],
            'to' => ['required', 'string', 'max:24', 'in:pending,confirmed,processing,completed,cancelled,unpaid,paid,partially_refunded,refunded,failed,unfulfilled,partially_fulfilled,fulfilled,partially_returned,returned'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
