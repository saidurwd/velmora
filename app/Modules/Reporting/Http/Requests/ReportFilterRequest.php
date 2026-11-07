<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Requests;

use App\Modules\Reporting\DTOs\ReportFilter;
use App\Modules\Reporting\Services\ReportDays;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** A period (default: the last 30 days up to today) and optional choices. Who may look is decided by the route's permission, not here. */
class ReportFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'channel' => ['nullable', 'in:web,api,admin'],
            'method' => ['nullable', 'regex:/^[a-z0-9_]{1,30}$/'],
            'locale' => ['nullable', 'in:'.implode(',', (array) config('app.supported_locales'))],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $filter = $this->filter();
            $days = app(ReportDays::class);
            if (! $days->isDay($filter->from) || ! $days->isDay($filter->to)) {
                $v->errors()->add('from', __('reporting::reports.invalid_day'));

                return;
            }
            if ($filter->to < $filter->from) {
                $v->errors()->add('to', __('reporting::reports.to_before_from'));
            } elseif (count($days->range($filter->from, $filter->to)) > (int) config('reporting.max_range_days')) {
                $v->errors()->add('to', __('reporting::reports.too_long', ['days' => (int) config('reporting.max_range_days')]));
            }
        });
    }

    public function filter(): ReportFilter
    {
        $days = app(ReportDays::class);
        $to = (string) ($this->input('to') ?: $days->today());
        $from = (string) ($this->input('from') ?: ($days->isDay($to) ? $days->shift($to, -29) : $days->shift($days->today(), -29)));

        return new ReportFilter($from, $to, $this->input('channel') ?: null, $this->input('method') ?: null, $this->input('locale') ?: null);
    }
}
