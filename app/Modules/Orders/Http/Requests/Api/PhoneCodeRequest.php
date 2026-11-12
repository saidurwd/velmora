<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests\Api;

use App\Modules\Platform\DTOs\PhoneNumber;
use App\Modules\Platform\Support\BanglaText;
use Illuminate\Foundation\Http\FormRequest;

class PhoneCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // abuse is limited by the code service (per number, per address, per day) and by the route throttle
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:40', function (string $a, mixed $v, \Closure $fail): void {
                PhoneNumber::tryParse(BanglaText::digitsToAscii((string) $v)) !== null || $fail(__('customers::account.addresses.errors.phone_invalid'));
            }],
        ];
    }

    public function phone(): PhoneNumber
    {
        return PhoneNumber::parse(BanglaText::digitsToAscii((string) $this->validated('phone')));
    }
}
