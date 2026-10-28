<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Http\Requests;

use App\Modules\Platform\DTOs\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;

class PhoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:40', function (string $attribute, mixed $value, \Closure $fail): void {
                if (PhoneNumber::tryParse((string) $value) === null) {
                    $fail(__('identity-access::auth.phone.invalid'));
                }
            }],
        ];
    }

    public function phone(): PhoneNumber
    {
        return PhoneNumber::parse((string) $this->validated('phone'));
    }
}
