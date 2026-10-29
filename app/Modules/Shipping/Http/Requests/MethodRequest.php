<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Http\Requests;

use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\Exceptions\MoneyException;
use App\Modules\Platform\Support\BanglaText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin') !== null; // the route's permission middleware decides who
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $default = (string) config('app.default_locale');
        $creating = $this->route('method') === null;

        return [
            'code' => $creating ? ['required', 'string', 'max:40', 'regex:/^[a-z0-9_-]+$/', Rule::unique('shipping_methods', 'code')] : ['prohibited'],
            'translations' => ['required', 'array'],
            "translations.{$default}.name" => ['required', 'string', 'max:120'],
            'translations.*.name' => ['nullable', 'string', 'max:120'],
            'translations.*.delivery_estimate' => ['nullable', 'string', 'max:160'],
            'cod_available' => ['sometimes', 'boolean'],
            'cod_fee' => ['nullable', 'string', 'max:20'],
            'free_over' => ['nullable', 'string', 'max:20'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('sort_order')) {
            $this->merge(['sort_order' => BanglaText::numeric((string) $this->input('sort_order'))]);
        }
    }

    /** @return callable[] */
    public function after(): array
    {
        return [function ($validator): void {
            foreach (['cod_fee', 'free_over'] as $field) {
                if ($this->filled($field) && $this->money((string) $this->input($field)) === null) {
                    $validator->errors()->add($field, __('shipping::admin.errors.money_invalid'));
                }
            }
        }];
    }

    /** Taka as typed ("60", "60.50", "৬০") to paisa, or null when it is not an amount. */
    public function money(string $typed): ?int
    {
        try {
            $money = Money::fromMajorString($typed);

            return $money->isNegative() ? null : $money->amountMinor;
        } catch (MoneyException) {
            return null;
        }
    }

    /** @return array<string, array{name?: string|null, delivery_estimate?: string|null}> */
    public function translations(): array
    {
        /** @var array<string, array{name?: string|null, delivery_estimate?: string|null}> $t */
        $t = (array) $this->validated('translations', []);

        return $t;
    }
}
