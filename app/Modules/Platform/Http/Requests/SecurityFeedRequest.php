<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Requests;

use App\Modules\Platform\Support\AuditCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The filters of the activity log, security events and login history (GET). Who may look is decided by the route's permission; this only checks values.
 * A bad value is an error on the form, never silently ignored.
 */
class SecurityFeedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'person' => ['nullable', 'integer', 'min:1'],
            'customer' => ['nullable', 'string', 'max:100'],
            'kind' => ['nullable', Rule::in(['staff', 'customer'])],
            'area' => ['nullable', Rule::in(array_values(array_unique(AuditCatalog::AREAS)))],
            'result' => ['nullable', Rule::in(['success', 'failure', 'locked', 'signout'])],
            'severity' => ['nullable', Rule::in(['high', 'medium', 'info'])],
            'before' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array{from: string, to: string, person: string, customer: string, kind: string, area: string, result: string, severity: string} the values to show back in the form */
    public function filters(): array
    {
        $v = $this->validated();

        return [
            'from' => (string) ($v['from'] ?? ''), 'to' => (string) ($v['to'] ?? ''), 'person' => (string) ($v['person'] ?? ''), 'customer' => trim((string) ($v['customer'] ?? '')),
            'kind' => (string) ($v['kind'] ?? ''), 'area' => (string) ($v['area'] ?? ''), 'result' => (string) ($v['result'] ?? ''), 'severity' => (string) ($v['severity'] ?? ''),
        ];
    }

    public function before(): ?int
    {
        return isset($this->validated()['before']) ? (int) $this->validated()['before'] : null;
    }
}
