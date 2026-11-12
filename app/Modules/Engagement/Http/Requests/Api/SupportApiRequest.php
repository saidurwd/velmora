<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Http\Requests\Api;

use App\Modules\Engagement\Actions\OpenSupportTicket;
use Illuminate\Foundation\Http\FormRequest;

/** A new ticket (a subject, a kind and perhaps an order) or a reply (just the words). Words only in v1: no files. */
class SupportApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route requires a signed-in customer; the Actions find only their own tickets and orders
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = ['body' => ['required', 'string', 'min:5', 'max:'.(int) config('engagement.support.body_max', 5000)]];

        if ($this->routeIs('api.v1.support.tickets.store')) {
            $rules += [
                'subject' => ['required', 'string', 'min:3', 'max:150'],
                'category' => ['required', 'in:'.implode(',', OpenSupportTicket::CATEGORIES)],
                'order' => ['nullable', 'string', 'size:26'],
            ];
        }

        return $rules;
    }
}
