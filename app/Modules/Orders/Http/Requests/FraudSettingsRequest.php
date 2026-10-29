<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests;

use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\Exceptions\MoneyException;
use App\Modules\Platform\Support\BanglaText;
use Illuminate\Foundation\Http\FormRequest;

class FraudSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin') !== null; // settings.manage is checked by the route
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'enabled' => ['sometimes', 'boolean'],
            'max_open_cod_per_phone' => ['required', 'integer', 'min:0', 'max:50'],
            'velocity_orders_per_day' => ['required', 'integer', 'min:0', 'max:200'],
            'address_max_phones' => ['required', 'integer', 'min:0', 'max:50'],
            'high_value_cod' => ['required', 'string', 'max:20'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['max_open_cod_per_phone', 'velocity_orders_per_day', 'address_max_phones'] as $key) {
            if ($this->has($key)) {
                $this->merge([$key => BanglaText::numeric((string) $this->input($key))]);
            }
        }
    }

    /** @return callable[] */
    public function after(): array
    {
        return [function ($validator): void {
            if ($this->filled('high_value_cod') && $this->highValueMinor() === null) {
                $validator->errors()->add('high_value_cod', __('orders::fraud.errors.money_invalid'));
            }
        }];
    }

    /** Taka as typed to paisa (0 allowed: it turns the rule off); null when it is not an amount. */
    public function highValueMinor(): ?int
    {
        try {
            $m = Money::fromMajorString((string) $this->input('high_value_cod'));

            return $m->isNegative() ? null : $m->amountMinor;
        } catch (MoneyException) {
            return null;
        }
    }

    /** @return array<string, bool|int> */
    public function settings(): array
    {
        return [
            'fraud.enabled' => $this->boolean('enabled'),
            'fraud.max_open_cod_per_phone' => (int) $this->validated('max_open_cod_per_phone'),
            'fraud.velocity_orders_per_day' => (int) $this->validated('velocity_orders_per_day'),
            'fraud.address_max_phones' => (int) $this->validated('address_max_phones'),
            'fraud.high_value_cod_minor' => (int) $this->highValueMinor(),
        ];
    }
}
