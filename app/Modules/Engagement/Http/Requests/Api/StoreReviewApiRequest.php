<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Http\Requests\Api;

use App\Modules\Engagement\Actions\SubmitReview;
use App\Modules\Platform\Support\BanglaText;
use Illuminate\Foundation\Http\FormRequest;

class StoreReviewApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route requires a signed-in customer; the Action checks they bought it
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['title' => SubmitReview::clean((string) $this->input('title')), 'body' => SubmitReview::clean((string) $this->input('body')), 'rating' => BanglaText::digitsToAscii((string) $this->input('rating'))]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $noLinks = fn (string $attribute, mixed $value, \Closure $fail) => SubmitReview::hasLink((string) $value) ? $fail(__('engagement::errors.links_not_allowed')) : null;

        return [
            'variant' => ['required', 'string', 'size:26'],
            'rating' => ['required', 'integer', 'between:1,5'],
            'title' => ['nullable', 'string', 'max:'.SubmitReview::TITLE_MAX, $noLinks],
            'body' => ['required', 'string', 'min:'.SubmitReview::BODY_MIN, 'max:'.SubmitReview::BODY_MAX, $noLinks],
        ];
    }
}
