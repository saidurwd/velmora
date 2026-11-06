<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Http\Requests;

use App\Modules\Engagement\Actions\OpenSupportTicket;
use Illuminate\Foundation\Http\FormRequest;

/** A new ticket (with a subject, a category and perhaps an order) or a reply (just the words). The checks that matter are repeated in the Actions. */
class SupportMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web') !== null || $this->user('admin') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'body' => ['required', 'string', 'min:5', 'max:'.(int) config('engagement.support.body_max', 5000)],
            'files' => ['nullable', 'array', 'max:'.(int) config('engagement.support.attachments_per_message', 3)],
            'files.*' => ['file', 'max:'.(int) (config('engagement.support.attachment_max_bytes', 5_242_880) / 1024)],
        ];
        if ($this->routeIs('account.support.store')) {
            $rules += ['subject' => ['required', 'string', 'min:3', 'max:150'], 'category' => ['required', 'in:'.implode(',', OpenSupportTicket::CATEGORIES)], 'order' => ['nullable', 'string', 'size:26', 'alpha_num']];
        }
        if ($this->routeIs('admin.support.reply')) {
            $rules += ['internal' => ['sometimes', 'boolean']];
        }

        return $rules;
    }
}
