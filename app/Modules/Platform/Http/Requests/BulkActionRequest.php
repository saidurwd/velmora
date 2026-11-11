<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Requests;

use App\Modules\Platform\Contracts\BulkAction;
use App\Modules\Platform\Support\BulkActions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The rows chosen on a list, and the answers to what the action asks. Whether this person may run the action is decided by the controller (404 for an unknown one, 403 for one they may not run). */
class BulkActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function bulk(): BulkAction
    {
        return app(BulkActions::class)->find((string) $this->route('action')) ?? abort(404);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = ['ids' => ['required', 'array', 'min:1', 'max:'.BulkActions::MAX_ROWS], 'ids.*' => ['required', 'string', 'regex:/^[0-9a-z]{26}$/']];
        $user = $this->user('admin');
        if ($user === null || ! $this->routeIs('admin.bulk.run')) {
            return $rules;   // the confirmation page is where the answers are asked for, so only the run needs them
        }
        foreach ($this->bulk()->fields($user) as $field) {
            $rules['p.'.$field['name']] = [$field['required'] ? 'required' : 'nullable', 'string', $field['type'] === 'select' ? Rule::in(array_keys($field['options'] ?? [])) : 'max:'.($field['max'] ?? 255)];
        }

        return $rules;
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_values(array_unique(array_map('strval', (array) $this->input('ids', []))));
    }

    /** @return array<string, string> only what the action asks for, trimmed */
    public function params(): array
    {
        $user = $this->user('admin');
        $out = [];
        foreach ($user === null ? [] : $this->bulk()->fields($user) as $field) {
            $out[$field['name']] = trim((string) data_get($this->all(), 'p.'.$field['name'], ''));
        }

        return $out;
    }
}
